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
| `smoke-modules.mjs` | Opens every screen and fails on any script error |

The earlier per-phase suites (diagnostics, optical, pharmacy, theatre, stores,
reports) lived in a temporary folder that Windows cleaned out, so they are gone.
That is why these live in the project now.
