#!/bin/sh
# Every suite, in the order each one's data needs. Run from anywhere.
cd "$(dirname "$0")"
run() { name="$1"; echo "== $name"; shift; "$@" || { echo "FAILED: $name"; FAILED="$FAILED
  $name"; }; }

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
run "lens change api"     node lens-change.mjs
run "lens change browser" node lens-change-browser.mjs
run "pharmacy balance api" node pharmacy-balance.mjs
run "pharmacy balance browser" node pharmacy-balance-browser.mjs
run "optical waiting api"  node optical-waiting.mjs
run "optical waiting browser" node optical-waiting-browser.mjs
run "operation templates api" node operation-templates.mjs
run "operation templates browser" node operation-templates-browser.mjs
run "pharmacy refill api"  node pharmacy-refill.mjs
run "pharmacy refill browser" node pharmacy-refill-browser.mjs
run "theatre errors browser" node theatre-errors-browser.mjs
run "post-op layout browser" node postop-layout-browser.mjs
run "diagnostic rx api"   node diagnostic-prescriptions.mjs
run "diagnostic rx browser" node diagnostic-prescriptions-browser.mjs
run "theatre worklists"   node theatre-worklists-browser.mjs
run "diagnostic payment"  node diagnostic-payment-browser.mjs
run "walk-in approvals"   node walkin-approvals.mjs
run "walk-in refund browser" node walkin-refund-browser.mjs
run "discount documents api" node discount-documents.mjs
run "discount documents browser" node discount-documents-browser.mjs
run "all screens"         node smoke-modules.mjs

if [ -n "$FAILED" ]; then printf "\nSuites that failed or crashed:%b\n" "$FAILED"; exit 1; fi
echo "All suites passed."
