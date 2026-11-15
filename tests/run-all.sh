#!/bin/sh
# Every suite, in the order each one's data needs. Run from anywhere.
cd "$(dirname "$0")"
run() { echo "== $1"; shift; "$@"; }

sh reset.sh >/dev/null 2>&1
run "file numbers"        node file-numbers.mjs
run "uat round 1 api"     node uat-round1.mjs
run "uat round 1 browser" node uat-round1-browser.mjs
run "uat round 2 api"     node uat-round2.mjs
run "uat round 2 browser" node uat-round2-browser.mjs
run "mtuha api"           node mtuha.mjs
run "mtuha browser"       node mtuha-browser.mjs
run "uat round 3 api"     node uat-round3.mjs
run "uat round 3 browser" node uat-round3-browser.mjs
run "frames api"          node frames.mjs
run "frames browser"      node frames-browser.mjs
run "multi rx browser"    node multi-rx-browser.mjs
run "self approval"       node self-approval.mjs
run "searchable dropdowns" node searchable-select-browser.mjs
run "fast track api"      node fast-track.mjs
run "fast track browser"  node fast-track-browser.mjs
run "default dates"       node default-dates-browser.mjs
run "form errors"         node form-errors-browser.mjs
run "theatre print"       node theatre-print-browser.mjs
run "uat round 4 api"     node uat-round4.mjs
run "uat round 4 browser" node uat-round4-browser.mjs
run "results api"         node results-history.mjs
run "results browser"     node results-history-browser.mjs
run "optometry orders"     node optometry-orders.mjs
run "optometry browser"    node optometry-orders-browser.mjs
run "all screens"         node smoke-modules.mjs
