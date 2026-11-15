<?php
/**
 * Reference data the application needs to run. Idempotent: safe to run after every deploy.
 * Existing rows edited by administrators (role permissions, prices, settings) are not overwritten,
 * except the locked Admin role, which is always re-synced to hold every permission.
 */
declare(strict_types=1);

return static function (PDO $pdo): void {
    // ── Permission catalogue (code, category, label) ──────────────────────
    $permissions = [
        ['dashboard.view', 'Dashboard', 'View dashboard'],

        ['patients.view', 'Patients', 'View patient records'],
        ['patients.create', 'Patients', 'Register patients'],
        ['patients.update', 'Patients', 'Edit patient details'],
        ['patients.number.correct', 'Patients', 'Correct a patient\'s file number (with a reason)'],
        ['patients.files.view', 'Patients', 'View scanned patient files'],
        ['patients.files.upload', 'Patients', 'Upload patient files'],

        ['visits.view', 'Visits', 'View visits'],
        ['visits.create', 'Visits', 'Create visits'],
        ['visits.cancel', 'Visits', 'Cancel visits'],
        ['visits.emergency', 'Visits', 'Send emergencies for treatment before payment'],
        ['visits.followups.view', 'Visits', 'View follow-ups due'],
        ['visits.followups.manage', 'Visits', 'Cancel follow-ups'],

        ['payments.view', 'Cashier', 'View payment queue'],
        ['payments.process', 'Cashier', 'Receive payments'],
        ['payments.discount.request', 'Cashier', 'Request discounts'],
        ['payments.cancel.request', 'Cashier', 'Request payment cancellation'],
        ['payments.history', 'Cashier', 'View payment history'],
        ['payments.fast_track', 'Cashier', 'Upgrade a visit to fast track'],
        ['refunds.view', 'Cashier', 'View refund requests'],
        ['refunds.request', 'Cashier', 'Request refunds'],
        ['refunds.payout', 'Cashier', 'Record approved refunds as paid out'],

        ['approvals.view', 'Approvals', 'View approval requests'],
        ['approvals.decide', 'Approvals', 'Approve or reject requests'],
        ['approvals.decide_own', 'Approvals', 'Approve or reject their own requests (marked self-approved)'],
        ['approvals.increase', 'Approvals', 'Approve a discount larger than requested, up to the amount owed'],

        ['triage.view', 'Clinical', 'View triage queue'],
        ['triage.record', 'Clinical', 'Record triage assessments'],
        ['optometry.view', 'Clinical', 'View optometry queue'],
        ['optometry.record', 'Clinical', 'Record optometry assessments'],
        ['ophthalmology.view', 'Clinical', 'View ophthalmology queue'],
        ['ophthalmology.record', 'Clinical', 'Record ophthalmology consultations'],
        ['ophthalmology.investigations.request', 'Clinical', 'Request investigations from a consultation'],
        ['ophthalmology.surgery.recommend', 'Clinical', 'Recommend surgery from a consultation'],

        ['theatre.booking.view', 'Theatre', 'View theatre bookings'],
        ['theatre.booking.manage', 'Theatre', 'Create and edit theatre bookings'],
        ['theatre.preop.view', 'Theatre', 'View pre-op queue'],
        ['theatre.preop.record', 'Theatre', 'Record pre-op assessments'],
        ['theatre.intraop.view', 'Theatre', 'View intra-op queue'],
        ['theatre.intraop.record', 'Theatre', 'Record intra-op notes'],
        ['theatre.postop.view', 'Theatre', 'View post-op cases'],
        ['theatre.postop.record', 'Theatre', 'Record post-op visits and discharge'],
        ['theatre.history.view', 'Theatre', 'View theatre history'],
        ['theatre.booking.cancel', 'Theatre', 'Request cancellation of a theatre booking'],
        ['theatre.booking.defer_payment', 'Theatre', 'Allow an emergency case to go ahead before payment'],

        ['diagnostics.booking.view', 'Diagnostics', 'View diagnostic bookings'],
        ['diagnostics.booking.manage', 'Diagnostics', 'Create and edit diagnostic bookings'],
        ['diagnostics.report.view', 'Diagnostics', 'View reporting queue'],
        ['diagnostics.report.record', 'Diagnostics', 'Write diagnostic reports'],
        ['diagnostics.history.view', 'Diagnostics', 'View diagnostic history'],
        ['diagnostics.booking.checkin', 'Diagnostics', 'Check patients in and raise the test bill'],
        ['diagnostics.booking.cancel', 'Diagnostics', 'Request cancellation of a booking'],
        ['diagnostics.nocharge.request', 'Diagnostics', 'Request that a test is not charged'],

        ['optical.view', 'Optical Room', 'View optical orders'],
        ['optical.orders.manage', 'Optical Room', 'Create and track optical orders'],
        ['optical.dispense', 'Optical Room', 'Dispense spectacles'],
        ['optical.discount.request', 'Optical Room', 'Request a discount on a spectacle order'],
        ['optical.cancel.request', 'Optical Room', 'Request cancellation of a spectacle order'],
        ['optical.frames.manage', 'Optical Room', 'Manage the frame list and correct frame stock'],

        ['pharmacy.orders.view', 'Pharmacy', 'View medication orders'],
        ['pharmacy.orders.review', 'Pharmacy', 'Review orders and raise invoices'],
        ['pharmacy.dispense', 'Pharmacy', 'Dispense, part-dispense and refill'],
        ['pharmacy.external_sale', 'Pharmacy', 'Sell to walk-in customers'],
        ['pharmacy.history.view', 'Pharmacy', 'View pharmacy history'],
        ['pharmacy.inventory.view', 'Pharmacy', 'View pharmacy inventory'],
        ['pharmacy.stock.manage', 'Pharmacy', 'Record stock received and correct stock counts'],
        ['pharmacy.cancel', 'Pharmacy', 'Cancel what is left of an order'],

        ['requisitions.create', 'Requisitions', 'Create requisitions'],
        ['requisitions.view_own', 'Requisitions', "View own department's requisitions"],
        ['requisitions.manage', 'Requisitions', 'Approve, reject and dispatch requisitions'],

        ['stores.stock.view', 'Stores', 'View store inventory'],
        ['stores.stock.manage', 'Stores', 'Add items and adjust stock'],
        ['stores.procurement.view', 'Stores', 'View quotations, purchase orders and history'],
        ['stores.quotations.manage', 'Stores', 'Manage quotations'],
        ['stores.lpo.manage', 'Stores', 'Create purchase orders'],
        ['stores.lpo.approve_admin', 'Stores', 'Approve purchase orders (administration)'],
        ['stores.lpo.approve_store', 'Stores', 'Approve purchase orders (store manager)'],
        ['stores.grn.view', 'Stores', 'View goods received notes'],
        ['stores.grn.manage', 'Stores', 'Record goods received'],
        ['stores.grn.approve', 'Stores', 'Verify and approve goods received'],
        ['stores.suppliers.manage', 'Stores', 'Add and edit suppliers'],

        ['reports.view', 'Reports', 'Open report terminal'],
        ['reports.financial', 'Reports', 'Financial reports'],
        ['reports.clinical', 'Reports', 'Clinical reports'],
        ['reports.export', 'Reports', 'Export and print reports'],
        ['mtuha.view', 'MTUHA', 'View the MTUHA registers: Main, Surgery, Pediatric and Glaucoma'],

        ['users.view', 'Administration', 'View staff accounts and roles'],
        ['users.manage', 'Administration', 'Register and edit staff accounts'],
        ['roles.manage', 'Administration', 'Create and edit roles'],
        ['sessions.manage', 'Administration', 'View and end user sessions'],
        ['settings.view', 'Administration', 'View system settings'],
        ['settings.manage', 'Administration', 'Change system settings, departments and designations'],
        ['prices.view', 'Administration', 'View price list'],
        ['prices.manage', 'Administration', 'Change prices'],
        ['catalogues.manage', 'Administration', 'Manage ICD-10 codes, drugs, examination findings and dropdown lists'],
        ['audit.view', 'Administration', 'View audit log'],
        ['access_log.view', 'Administration', 'View patient record access log'],
        ['admin_reports.view', 'Administration', 'View administration reports'],
    ];
    $existingCodes = $pdo->query('SELECT code FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare(
        'INSERT INTO permissions (code, category, label, sort_order) VALUES (:code, :category, :label, :sort)
         ON DUPLICATE KEY UPDATE category = VALUES(category), label = VALUES(label), sort_order = VALUES(sort_order)'
    );
    foreach ($permissions as $i => [$code, $category, $label]) {
        $stmt->execute(['code' => $code, 'category' => $category, 'label' => $label, 'sort' => ($i + 1) * 10]);
    }
    $permissionIds = $pdo->query('SELECT code, id FROM permissions')->fetchAll(PDO::FETCH_KEY_PAIR);
    // Permissions introduced by this release are also given to existing default roles whose
    // patterns cover them; permissions administrators already removed are never re-added.
    $newPermissionIds = array_map('intval', array_values(array_intersect_key($permissionIds, array_flip(array_diff(array_keys($permissionIds), $existingCodes)))));

    $resolve = static function (array $patterns) use ($permissionIds): array {
        $ids = [];
        foreach ($patterns as $pattern) {
            foreach ($permissionIds as $code => $id) {
                $match = $pattern === '*'
                    || $code === $pattern
                    || (str_ends_with($pattern, '.*') && str_starts_with($code, substr($pattern, 0, -1)));
                if ($match) {
                    $ids[$id] = true;
                }
            }
        }
        return array_keys($ids);
    };

    // ── Default roles (created once; afterwards administrators own them) ──
    $selfService = ['requisitions.create', 'requisitions.view_own'];
    $roles = [
        'admin' => ['Admin', 'Full system access. Always holds every permission.', 'purple', true, true, ['*']],
        'reception' => ['Reception', 'Front desk — patient registration and visits.', 'blue', true, false, [
            'dashboard.view', 'patients.view', 'patients.create', 'patients.update', 'patients.files.view', 'patients.files.upload',
            'visits.*', ...$selfService]],
        'cashier' => ['Cashier', 'Billing, payments, discount and refund requests.', 'amber', true, false, [
            'dashboard.view', 'patients.view', 'visits.view', 'payments.*', 'refunds.*', 'reports.view', 'reports.financial', ...$selfService]],
        'clinical' => ['Clinical', 'Nursing and clinical support — triage, optometry, theatre nursing.', 'teal', true, false, [
            'dashboard.view', 'patients.view', 'visits.view', 'visits.emergency', 'triage.*', 'optometry.*', 'ophthalmology.view',
            'theatre.booking.view', 'theatre.preop.*', 'theatre.intraop.*', 'theatre.postop.*', 'theatre.history.view',
            'diagnostics.booking.view', 'diagnostics.booking.manage', 'diagnostics.booking.checkin', 'diagnostics.booking.cancel',
            'diagnostics.report.view', 'diagnostics.report.record', 'diagnostics.history.view', 'diagnostics.nocharge.request',
            'reports.view', ...$selfService]],
        'doctor' => ['Doctor', 'Ophthalmologists — consultations, surgery and diagnostic reporting.', 'green', true, false, [
            'dashboard.view', 'patients.view', 'patients.files.view', 'visits.view', 'visits.emergency', 'triage.view', 'optometry.view',
            'ophthalmology.*', 'theatre.*', 'diagnostics.*', 'pharmacy.orders.view', 'reports.view', 'reports.clinical', 'mtuha.view', ...$selfService]],
        'pharmacist' => ['Pharmacist', 'Medication orders, dispensing and walk-in sales.', 'green', true, false, [
            'dashboard.view', 'patients.view', 'pharmacy.*', 'reports.view', ...$selfService]],
        'optical' => ['Optical Technician', 'Spectacle orders, supplier tracking and dispensing.', 'blue', true, false, [
            'dashboard.view', 'patients.view', 'optical.*', 'reports.view', ...$selfService]],
        'storekeeper' => ['Storekeeper', 'Stores — requisitions, stock and procurement.', 'teal', true, false, [
            'dashboard.view', 'requisitions.*', 'stores.stock.*', 'stores.procurement.view', 'stores.quotations.manage',
            'stores.lpo.manage', 'stores.lpo.approve_store', 'stores.suppliers.manage', 'stores.grn.*', 'reports.view']],
        // Given beside someone's usual role: they may decide discounts, refunds and cancellations,
        // including requests they raised themselves; those are marked self-approved everywhere.
        'senior_approver' => ['Senior Approver', 'Decides discounts, refunds and cancellations, including their own requests (marked self-approved).', 'purple', true, false, [
            'approvals.view', 'approvals.decide', 'approvals.decide_own', 'approvals.increase', 'reports.view', 'reports.financial']],
    ];
    $findRole = $pdo->prepare('SELECT id FROM roles WHERE code = :code');
    $insertRole = $pdo->prepare(
        'INSERT INTO roles (code, name, description, color, is_system, is_locked, created_at) VALUES (:code, :name, :description, :color, :system, :locked, UTC_TIMESTAMP(3))'
    );
    $grant = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id, granted_at) VALUES (:r, :p, UTC_TIMESTAMP(3))');
    foreach ($roles as $code => [$name, $description, $color, $system, $locked, $patterns]) {
        $findRole->execute(['code' => $code]);
        $roleId = $findRole->fetchColumn();
        $isNew = $roleId === false;
        if ($isNew) {
            $insertRole->execute(['code' => $code, 'name' => $name, 'description' => $description, 'color' => $color, 'system' => (int) $system, 'locked' => (int) $locked]);
            $roleId = (int) $pdo->lastInsertId();
        }
        $toGrant = ($isNew || $locked) ? $resolve($patterns) : array_intersect($resolve($patterns), $newPermissionIds);
        foreach ($toGrant as $permissionId) {
            $grant->execute(['r' => $roleId, 'p' => $permissionId]);
        }
    }

    // ── Departments & designations ────────────────────────────────────────
    $stmt = $pdo->prepare('INSERT IGNORE INTO departments (name, created_at) VALUES (:n, UTC_TIMESTAMP(3))');
    foreach (['Reception', 'Cashier', 'Triage', 'Optometry', 'Ophthalmology', 'Theatre / Surgery', 'Diagnostics / Investigations',
              'Optical Room', 'Pharmacy', 'Stores', 'Administration', 'Finance'] as $name) {
        $stmt->execute(['n' => $name]);
    }
    $stmt = $pdo->prepare('INSERT IGNORE INTO designations (code, name, created_at) VALUES (:c, :n, UTC_TIMESTAMP(3))');
    foreach ([
        'ophthalmologist' => 'Ophthalmologist', 'surgeon' => 'Surgeon', 'optometrist' => 'Optometrist', 'nurse' => 'Nurse',
        'scrub_nurse' => 'Scrub Nurse', 'anaesthesia_nurse' => 'Anaesthesia Nurse', 'theatre_runner' => 'Theatre Runner',
        'diagnostic_technician' => 'Diagnostic Technician', 'pharmacist' => 'Pharmacist', 'optical_technician' => 'Optical Technician',
        'receptionist' => 'Receptionist', 'cashier' => 'Cashier', 'storekeeper' => 'Storekeeper', 'administrator' => 'Administrator',
    ] as $code => $name) {
        $stmt->execute(['c' => $code, 'n' => $name]);
    }

    // ── Dropdown lists ────────────────────────────────────────────────────
    $lists = [
        'gender' => ['Male', 'Female'],
        'kin_relationship' => ['Spouse', 'Parent', 'Sibling', 'Child', 'Guardian', 'Other'],
        'payment_method' => ['Cash', 'Lipa Number'],
        'discount_type' => ['Discount', 'Welfare', 'Complimentary', 'Exemption', 'Invoice Discount', 'Staff Welfare', 'Glaucoma Club', 'Sababa', 'Mnazi Mmoja', 'Debt'],
        'consultation_room' => ['Room 1', 'Room 2', 'Room 3', 'Room 4'],
        'optometry_room' => ['Optometry Room 1', 'Optometry Room 2'],
        'eye' => ['Right', 'Left', 'Both'],
        'emergency_reason' => ['Severe Eye Pain', 'Endophthalmitis', 'Corneal Ulcer', 'Foreign Body (Eye)', 'Chemical Injury', 'Eye Trauma',
            'Sudden Loss of Vision', 'Acute Glaucoma', 'Other'],
        'follow_up_reason' => ['Refraction Review', 'Low Vision Review', 'Orthoptic Review', 'Medication Review', 'Post Treatment Review',
            'Routine Review', 'IOP Monitoring', 'Post-Operative Review', 'Investigation Results Review', 'Other'],
        'drug_frequency' => ['OD', 'BD', 'TDS', 'QID', '5x/day', 'Hourly', 'PRN', 'Nocte', 'Stat', 'As directed'],
        'diagnostic_laser_type' => ['Retina', 'SLT', 'Iridotomy', 'YAG Capsulotomy', 'Hyaloidotomy', 'Laser Trichiasis'],
        'diagnostic_laser_subtype' => ['PRO', 'Focal/Grid', 'Barrage'],
        'no_charge_reason' => ['Staff', 'Outreach Camp', 'Repeat Test', 'Management Approval', 'Other'],
        'optical_supplier' => ['Supplier A', 'Supplier B'],
        'rx_label' => ['Distance', 'Bifocal', 'Progressive', 'Reading', 'Computer', 'Sunglasses'],
        'theatre_room' => ['Main Theatre', 'VIP Theatre', 'Minor Procedure Room'],
        'store_category' => ['Medicines', 'Surgical Supplies', 'Consumables', 'Optical Supplies',
            'Office Supplies', 'Equipment', 'Cleaning Materials', 'Other'],
        'store_unit' => ['Piece', 'Box', 'Bottle', 'Packet', 'Roll', 'Ream', 'Pair', 'Set', 'Litre', 'Vial'],
        'postop_counselling' => ['Cataract', 'General post-operative', 'Avastin injection', 'Procedure-specific'],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO lookup_values (list_code, value, label, sort_order, created_at) VALUES (:l, :v, :v2, :s, UTC_TIMESTAMP(3))');
    foreach ($lists as $list => $values) {
        foreach ($values as $i => $value) {
            $stmt->execute(['l' => $list, 'v' => $value, 'v2' => $value, 's' => ($i + 1) * 10]);
        }
    }

    // ── Settings ──────────────────────────────────────────────────────────
    $stmt = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description) VALUES (:k, :v, :t, :d)');
    foreach ([
        ['security.session_idle_minutes', '30', 'Minutes of inactivity before a user is signed out'],
        ['security.session_warning_seconds', '120', 'Seconds before sign-out when the "stay signed in" warning appears'],
        ['security.session_absolute_hours', '12', 'Maximum length of any session, in hours'],
        ['security.login_max_attempts', '5', 'Failed sign-ins before an account is temporarily locked'],
        ['security.lockout_minutes', '15', 'Minutes an account stays locked after too many failed sign-ins'],
        ['security.password_min_length', '10', 'Minimum password length'],
        ['visits.revisit_exemption_days', '14', 'Days within which a return consultation is fee-exempt'],
        ['clinical.record_edit_hours', '24', 'Hours after completing a clinical record during which its author may still correct it'],
    ] as [$key, $value, $description]) {
        $stmt->execute(['k' => $key, 'v' => $value, 't' => 'int', 'd' => $description]);
    }

    // ── Price list (unconfirmed until finance signs off) ──────────────────
    $prices = [
        ['CONS-NORMAL', 'consultation', 'Consultation — Normal', 25000],
        ['CONS-FAST', 'consultation', 'Consultation — Fast Track', 40000],
        ['CONS-FOLLOWUP', 'consultation', 'Doctor Follow-Up', 15000],
        ['CONS-SURG-FOLLOWUP', 'consultation', 'Surgery Follow-Up', 15000],
        ['INV-OCT', 'investigation', 'OCT Scan', 80000],
        ['INV-AB-SCAN', 'investigation', 'A/B Scan', 65000],
        ['INV-VISUAL-FIELD', 'investigation', 'Visual Field', 55000],
        ['INV-YAG', 'investigation', 'YAG Laser', 120000],
        ['INV-SLT', 'investigation', 'SLT Laser', 120000],
        ['INV-PMT', 'investigation', 'PMT', 40000],
        ['INV-BIOMETRY', 'investigation', 'Biometry', 50000],
        ['INV-LASER', 'investigation', 'Laser Therapy', 120000],
        ['SURG-CATARACT-MSICS', 'surgery', 'Cataract (MSICS)', 850000],
        ['SURG-CHALAZION', 'surgery', 'Chalazion Excision', 120000],
        ['SURG-ECTROPION', 'surgery', 'Ectropion Repair', 300000],
        ['SURG-ENTROPION', 'surgery', 'Entropion Repair', 300000],
        ['SURG-ENUCLEATION', 'surgery', 'Enucleation', 600000],
        ['SURG-EVISCERATION', 'surgery', 'Evisceration', 550000],
        ['SURG-BLEPHAROPLASTY', 'surgery', 'Eyelid Repair (Blepharoplasty)', 400000],
        ['SURG-PINGUECULA', 'surgery', 'Pinguecula', 150000],
        ['SURG-PTERYGIUM', 'surgery', 'Pterygium', 250000],
        ['SURG-PTOSIS', 'surgery', 'Ptosis Correction', 350000],
        ['SURG-STRABISMUS', 'surgery', 'Strabismus Correction', 500000],
        ['PROC-AC-WASHOUT', 'procedure', 'A/C Washout', 50000],
        ['PROC-AVASTIN', 'procedure', 'Avastin Injection (per injection)', 120000],
        ['PROC-BTRP', 'procedure', 'BTRP', 80000],
        ['PROC-CPC', 'procedure', 'CPC', 200000],
        ['PROC-IOL-REMOVAL', 'procedure', 'IOL Removal', 250000],
        ['PROC-IOL-REPOSITION', 'procedure', 'IOL Redialing/Reposition', 200000],
        ['LENS-SV-CR39', 'lens', 'Standard SV CR-39', 45000],
        ['LENS-SV-AR', 'lens', 'Anti-Reflective SV CR-39', 65000],
        ['LENS-HI-160', 'lens', 'High-Index 1.60', 95000],
        ['LENS-HI-167', 'lens', 'High-Index 1.67', 130000],
        ['LENS-PROG-STD', 'lens', 'Progressive Standard', 180000],
        ['LENS-PROG-PREMIUM', 'lens', 'Progressive Premium (Varilux)', 280000],
        ['LENS-BIFOCAL', 'lens', 'Bifocal CR-39', 75000],
        ['LENS-READING', 'lens', 'Reading CR-39', 40000],
        ['LENS-PHOTOCHROMIC', 'lens', 'Photochromic SV', 110000],
        ['LENS-BLUE-LIGHT', 'lens', 'Blue-Light SV', 80000],
    ];
    $insertPrice = $pdo->prepare(
        'INSERT IGNORE INTO price_items (code, category, name, unit_price, currency, is_confirmed, created_at) VALUES (:c, :cat, :n, :p, :cur, 0, UTC_TIMESTAMP(3))'
    );
    $history = $pdo->prepare(
        'INSERT INTO price_history (price_item_id, old_price, new_price, changed_at, reason) VALUES (:id, NULL, :p, UTC_TIMESTAMP(3), :r)'
    );
    foreach ($prices as [$code, $category, $name, $price]) {
        $insertPrice->execute(['c' => $code, 'cat' => $category, 'n' => $name, 'p' => $price, 'cur' => 'TZS']);
        if ($insertPrice->rowCount() === 1) {
            $history->execute(['id' => (int) $pdo->lastInsertId(), 'p' => $price, 'r' => 'Initial price from prototype — awaiting finance confirmation']);
        }
    }

    // Laser therapy asks for its laser type when a booking is made.
    $pdo->exec("UPDATE price_items SET is_laser = 1 WHERE code = 'INV-LASER'");

    (require __DIR__ . '/clinical.php')($pdo);
};
