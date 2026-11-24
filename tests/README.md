# Tests

Run everything:

    sh tests/run-all.sh

`reset.sh` empties the local database and recreates the `sysadmin` administrator,
writing its password to `tests/admin-pass.txt` for the suites to read. Both are
local-development only — the installer refuses to reset a production database,
and the web server's whitelist keeps this whole folder unreachable from a browser.

| File | What it covers |
|---|---|
| `uat-round1.mjs` | The corrections the clinic asked for after reviewing the system (API side) |
| `uat-round1-browser.mjs` | The same corrections, seen on screen |
| `uat-round4.mjs` | Reception → Optometry, doctor's Clinical History and referral to Optometry (reopening the optometry record), discounts inside the Financial Summary |
| `uat-round4-browser.mjs` | The same, on screen (form order, rooms, Optometry after a doctor's referral, report tables) |
| `results-history.mjs` | Doctors read test results (consultation, history, bell alerts); fuller patient history; nurses see status only |
| `results-history-browser.mjs` | The same on screen, signed in as a Doctor and as a nurse |
| `optometry-orders.mjs` | Optometry books investigations and reads results (Optometrist role), records biometry; Theatre uses the latest biometry |
| `optometry-orders-browser.mjs` | The same on screen: Optometry cards and a new cataract booking filling its biometry |
| `lens-change.mjs` | Optical changes the lens (confirm page, or unpaid order not with the supplier; bill re-raised); blocked once paid or sent |
| `lens-change-browser.mjs` | The same on screen |
| `pharmacy-balance.mjs` | Take part of a prescription now, collect the balance later at the locked price; cancelled part returns to the balance; close balance |
| `pharmacy-balance-browser.mjs` | The same on screen: review note, Balance to Collect, Supply balance |
| `optical-waiting.mjs` | Order some spectacle pairs now, the rest later at the locked lens price; cancelled later order returns its pair; close waiting pairs |
| `optical-waiting-browser.mjs` | The same on screen: Order now ticks, waiting pair in Incoming |
| `operation-templates.mjs` | Operation templates: the clinic's five loaded, edited in Settings, joined on a booking, seeding never overwrites edits |
| `operation-templates-browser.mjs` | Intra-op notes and the first post-op review start from the template; the Settings editor |
| `pharmacy-refill.mjs` | Paid refills: own order at today's price, pay first, hand over; old free refill closed |
| `pharmacy-refill-browser.mjs` | The Refill window on screen |
| `theatre-errors-browser.mjs` | Pre-op errors show under the right box; unplaced errors name their field |
| `postop-layout-browser.mjs` | Post-Op: case list with filters and last/next review; a case opens with its reviews and New Post-Op Visit on the left, the review on the right, filling the screen |
| `diagnostic-prescriptions.mjs` | A diagnostic report prescribes medicine: sent with the report, pharmacy review, billed, paid, handed over; only once per report; can be added within the correction window |
| `diagnostic-prescriptions-browser.mjs` | The Medicines section on the report screen, and what was sent shown after saving |
| `theatre-worklists-browser.mjs` | Pre-Op lists only patients waiting for clearance and In Theatre only those waiting for their operation; the "Sent" switch shows those already moved on |
| `diagnostic-payment-browser.mjs` | A diagnostic report needs the test paid (or No charge approved; free tests are exempt): refused before check-in, while unpaid and while No charge waits; on screen the unpaid test explains why |
| `walkin-approvals.mjs` | A walk-in counter-sale bill (no patient) can be discounted, cancelled and refunded; the refund is found by bill, receipt or EXT number; a walk-in's approval, attachment, receipt, pharmacy order and external spectacle order all open |
| `walkin-refund-browser.mjs` | New Refund on screen: "Walk-in counter sale?" finds the paid bill by EXT or receipt number, Change clears it, the request is saved for the walk-in |
| `discount-documents.mjs` | A document attached to one discount request is on file for the patient: listed for the next request (cashier, optical, diagnostics "no charge") and shown to the approver with it; scoped to that patient; refused to users who neither request nor approve discounts |
| `discount-documents-browser.mjs` | The same on screen: the on-file list in the optical, diagnostics and cashier discount windows, and "Already on File" in the approver's view |
| `visit-discharge.mjs` | A visit is discharged automatically once the day's services are done (doctor, a test booked for today, medicines handed over), waits as "Awaiting Other Services" until then; Reception ends a visit by hand with a reason, or re-admits it (same visit, no new fee, the stage's record reopens as a draft); the limits and permissions |
| `visit-discharge-browser.mjs` | The registration form keeps what was typed through a stray click, closing and changing screens (Start over clears it; nothing stored in the browser); End Visit and Re-admit from the visit window; the doctor's banner shows the re-admission |
| `clinical-transfer.mjs` | To and fro between the doctor and Optometry: the optometrist refers back (the consultation reopens, its referral cleared), repeatedly; the doctor's queue lists patients with Optometry under their own filter and the doctor calls one over (one being seen there only with take-over; the optometry draft is kept) |
| `clinical-transfer-browser.mjs` | The same on screen: the unlocked Ophthalmology box at Optometry, the At Optometry list, Call to Doctor, "Back from Optometry" |
| `smoke-modules.mjs` | Opens every screen and fails on any script error |

The earlier per-phase suites (diagnostics, optical, pharmacy, theatre, stores,
reports) lived in a temporary folder that Windows cleaned out, so they are gone.
That is why these live in the project now.
