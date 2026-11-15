// UAT round 2: medicine prices live on the pharmacy's medicine list, and the store
// can put a medicine on that list and stock the pharmacy with it.
import { signIn, get, post, put, check, equal, expectError, report, sql } from './lib/api.mjs';

await signIn();
// Re-runnable without a database reset: everything this suite creates carries a run tag.
const run = String(Date.now()).slice(-6);

// ─── The Price List no longer has a Medicine category ────────────────────
await expectError('a price cannot be filed under Medicine any more',
  () => post('prices', { code: `MED-TEST-${run}`, category: 'medicine', name: `Test Drops ${run}`, unit_price: 4500 }), 422);
check('no price is left under Medicine', sql("SELECT COUNT(*) FROM price_items WHERE category = 'medicine'") === '0');

// ─── A price can be moved to another category ─────────────────────────────
const created = (await post('prices', { code: `CAT-TEST-${run}`, category: 'other', name: `Test Service ${run}`, unit_price: 1000 })).item;
const moved = (await put(`prices/${created.id}`, {
  category: 'procedure', name: created.name, unit_price: created.unit_price,
  is_confirmed: false, is_active: true, row_version: created.row_version,
})).item;
equal('the category can be changed', moved.category, 'procedure');
check('the change is in the audit log', sql(
  `SELECT COUNT(*) FROM audit_log WHERE action = 'price.updated' AND entity_id = '${created.id}' AND changes LIKE '%procedure%'`) === '1');
await expectError('an unknown category is refused', () => put(`prices/${created.id}`, {
  category: 'medicine', name: moved.name, unit_price: 1000, is_confirmed: false, is_active: true, row_version: moved.row_version,
}), 422);
const kept = (await put(`prices/${created.id}`, {
  name: moved.name, unit_price: 1000, is_confirmed: false, is_active: true, row_version: moved.row_version,
})).item;
equal('leaving the category out keeps it', kept.category, 'procedure');

// ─── The store adds a new medicine to the pharmacy in one step ────────────
const options = await get('stores/options');
const category = options.categories.find(c => /medic/i.test(c)) || options.categories[0];
const unit = options.units[0];
const name = `Test Timolol ${run} 5ml`;
await expectError('a new pharmacy medicine needs a selling price',
  () => post('stores/stock', { name, category, unit, drug_id: 'new' }), 422);
const item = (await post('stores/stock', {
  name, category, unit, drug_id: 'new', new_drug_price: 4000, new_drug_form: 'Eye Drops', cost_price: 2500,
})).item;
check('the store item is linked to a pharmacy medicine', item.drug_id > 0, item.drug_id);
equal('which has the same name', item.drug_name, name);
const drug = (await get('catalogues/drugs')).drugs.find(d => d.id === item.drug_id);
equal('and the selling price', drug?.unit_price, 4000);
equal('and the form', drug?.dosage_form, 'Eye Drops');
check('adding the medicine is audited', sql(
  `SELECT COUNT(*) FROM audit_log WHERE action = 'drug.created' AND entity_id = '${item.drug_id}'`) === '1');
await expectError('the same name cannot be added to the pharmacy twice',
  () => post('stores/stock', { name, category, unit, drug_id: 'new', new_drug_price: 4000 }), 422);

const plain = (await post('stores/stock', { name: `Test Gloves ${run}`, category, unit })).item;
equal('an item can still be left off the pharmacy list', plain.drug_id, null);

// ─── Issuing it to the pharmacy stocks the pharmacy ───────────────────────
await post(`stores/stock/${item.id}/adjust`, { change: 20, reason: 'adjusted', note: 'Opening count' });
const pharmacy = options.departments.find(d => /pharmac/i.test(d.name));
check('there is a Pharmacy department', !!pharmacy);
const requisition = (await post('stores/requisitions', {
  department_id: pharmacy.id, priority: 'normal', submit: true, items: [{ item_id: item.id, quantity: 6 }],
})).requisition;
// Approving and issuing refer to the requisition's own line, not the store item.
const lineId = requisition.items[0].id;
await post(`stores/requisitions/${requisition.id}/decide`, { decision: 'approved', items: [{ item_id: lineId, qty_approved: 6 }] });
await post(`stores/requisitions/${requisition.id}/issue`, { items: [{ item_id: lineId, quantity: 6 }] });
const shelf = (await get(`pharmacy/stock?search=${encodeURIComponent(name)}`)).items.find(i => i.drug_id === item.drug_id);
equal('the pharmacy now has the six issued', shelf?.quantity, 6);
equal('at the selling price', shelf?.unit_price, 4000);

// ─── The selling price of a linked medicine shows in the store form ───────
const listed = (await get('stores/options')).drugs.find(d => d.id === item.drug_id);
equal('the store sees what the pharmacy charges', listed?.unit_price, 4000);
const fresh = (await get(`stores/stock/${item.id}`)).item;
const linkedBody = { name, category, unit, drug_id: item.drug_id, is_active: true };
const repriced = (await put(`stores/stock/${item.id}`, { ...linkedBody, drug_price: 4500, row_version: fresh.row_version })).item;
equal('an administrator can change it from the store form',
  (await get('catalogues/drugs')).drugs.find(d => d.id === item.drug_id)?.unit_price, 4500);
check('the price change is audited', sql(
  `SELECT COUNT(*) FROM audit_log WHERE action = 'drug.updated' AND entity_id = '${item.drug_id}' AND changes LIKE '%4500%'`) === '1');

// A storekeeper sees the price but may not change it.
const roleId = sql("SELECT id FROM roles WHERE code = 'storekeeper'");
const departmentId = sql("SELECT id FROM departments WHERE name = 'Stores'");
const keeperName = `keeper${run}`;
const keeperPass = `Store-Keeper-${run}-x9`;
await post('users', {
  first_name: 'Test', last_name: 'Keeper', phone: `25576${run}1`, department_id: Number(departmentId),
  role_ids: [Number(roleId)], designation_ids: [], username: keeperName, password: keeperPass,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = '${keeperName}'`);
await signIn(keeperName, keeperPass);
await expectError('a storekeeper cannot change the selling price', () => put(`stores/stock/${item.id}`, {
  ...linkedBody, drug_price: 9000, row_version: repriced.row_version,
}), 403);
const sameAgain = (await put(`stores/stock/${item.id}`, { ...linkedBody, drug_price: 4500, row_version: repriced.row_version })).item;
check('but can save the item when the price is unchanged', sameAgain.row_version > repriced.row_version);
equal('and the price stays', (await get('stores/options')).drugs.find(d => d.id === item.drug_id)?.unit_price, 4500);
await signIn();

// ─── Any department can ask the store for goods ───────────────────────────
const nurseName = `nurse${run}`;
const nursePass = `Triage-Nurse-${run}-x9`;
await post('users', {
  first_name: 'Test', last_name: 'Nurse', phone: `25576${run}2`,
  department_id: Number(sql("SELECT id FROM departments WHERE name = 'Triage'")),
  role_ids: [Number(sql("SELECT id FROM roles WHERE code = 'clinical'"))], designation_ids: [],
  username: nurseName, password: nursePass,
});
sql(`UPDATE users SET must_change_password = 0 WHERE username = '${nurseName}'`);
await signIn(nurseName, nursePass);
await expectError('a nurse cannot see the full store list', () => get('stores/options'), 403);
const forNurse = await get('stores/requisition-options');
check('a nurse gets the departments', forNurse.departments.some(d => d.name === 'Triage'));
const seen = forNurse.items.find(i => i.id === item.id);
check('and the store items with how many are in stock', seen && seen.quantity === 14, JSON.stringify(seen));
check('but not what they cost', forNurse.items.every(i => !('cost_price' in i)));
equal('nor the suppliers or pharmacy prices', forNurse.suppliers.length + forNurse.drugs.length, 0);
const nurseRequest = (await post('stores/requisitions', {
  department_id: forNurse.departments.find(d => d.name === 'Triage').id, priority: 'normal', submit: true,
  items: [{ item_id: plain.id, quantity: 2 }],
})).requisition;
check('and can send a requisition to the store', !!nurseRequest.requisition_no, nurseRequest.status);
await signIn();

report('uat round 2');
