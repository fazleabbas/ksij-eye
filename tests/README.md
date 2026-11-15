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
| `smoke-modules.mjs` | Opens every screen and fails on any script error |

The earlier per-phase suites (diagnostics, optical, pharmacy, theatre, stores,
reports) lived in a temporary folder that Windows cleaned out, so they are gone.
That is why these live in the project now.
