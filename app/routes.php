<?php
declare(strict_types=1);

use App\Controllers\ApprovalController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\BillController;
use App\Controllers\CatalogueController;
use App\Controllers\DashboardController;
use App\Controllers\ClinicalController;
use App\Controllers\DiagnosticController;
use App\Controllers\OphthalmologyController;
use App\Controllers\OpticalController;
use App\Controllers\OptometryController;
use App\Controllers\PatientController;
use App\Controllers\PharmacyController;
use App\Controllers\TriageController;
use App\Controllers\PriceController;
use App\Controllers\RefundController;
use App\Controllers\ReportController;
use App\Controllers\VisitController;
use App\Controllers\ReferenceController;
use App\Controllers\RoleController;
use App\Controllers\SettingsController;
use App\Controllers\StoreController;
use App\Controllers\TheatreController;
use App\Controllers\UserController;
use App\Core\Router;

/** API route table: method, path, handler, required permission. */
return static function (Router $r): void {
    // Authentication & session
    $r->add('POST', '/auth/login',           [AuthController::class, 'login'],          Router::PUBLIC);
    $r->add('POST', '/auth/logout',          [AuthController::class, 'logout'],         Router::PUBLIC);
    $r->add('GET',  '/auth/me',              [AuthController::class, 'me'],             Router::SESSION);
    $r->add('GET',  '/auth/session',         [AuthController::class, 'session'],        Router::SESSION, touch: false);
    $r->add('POST', '/auth/keepalive',       [AuthController::class, 'keepalive'],      Router::SESSION);
    $r->add('POST', '/auth/change-password', [AuthController::class, 'changePassword'], Router::SESSION);

    // Staff accounts
    $r->add('GET',  '/users',                      [UserController::class, 'index'],          'users.view');
    $r->add('POST', '/users',                      [UserController::class, 'store'],          'users.manage');
    $r->add('GET',  '/users/{id}',                 [UserController::class, 'show'],           'users.view');
    $r->add('PUT',  '/users/{id}',                 [UserController::class, 'update'],         'users.manage');
    $r->add('POST', '/users/{id}/status',          [UserController::class, 'setStatus'],      'users.manage');
    $r->add('POST', '/users/{id}/reset-password',  [UserController::class, 'resetPassword'],  'users.manage');
    $r->add('POST', '/users/{id}/unlock',          [UserController::class, 'unlock'],         'users.manage');
    $r->add('GET',  '/users/{id}/sessions',        [UserController::class, 'sessions'],       'sessions.manage');
    $r->add('POST', '/users/{id}/revoke-sessions', [UserController::class, 'revokeSessions'], 'sessions.manage');

    // Roles & permissions
    $r->add('GET',    '/roles',       [RoleController::class, 'index'],       'users.view');
    $r->add('POST',   '/roles',       [RoleController::class, 'store'],       'roles.manage');
    $r->add('PUT',    '/roles/{id}',  [RoleController::class, 'update'],      'roles.manage');
    $r->add('DELETE', '/roles/{id}',  [RoleController::class, 'destroy'],     'roles.manage');
    $r->add('GET',    '/permissions', [RoleController::class, 'permissions'], 'users.view');

    // Reference data
    $r->add('GET',  '/reference/departments',       [ReferenceController::class, 'departments'],       Router::AUTH);
    $r->add('POST', '/reference/departments',       [ReferenceController::class, 'storeDepartment'],   'settings.manage');
    $r->add('PUT',  '/reference/departments/{id}',  [ReferenceController::class, 'updateDepartment'],  'settings.manage');
    $r->add('GET',  '/reference/designations',      [ReferenceController::class, 'designations'],      Router::AUTH);
    $r->add('POST', '/reference/designations',      [ReferenceController::class, 'storeDesignation'],  'settings.manage');
    $r->add('PUT',  '/reference/designations/{id}', [ReferenceController::class, 'updateDesignation'], 'settings.manage');
    $r->add('GET',  '/reference/lookups',           [ReferenceController::class, 'lookups'],           Router::AUTH);
    $r->add('GET',  '/reference/staff',             [ReferenceController::class, 'staff'],             Router::AUTH);

    // System settings
    $r->add('GET', '/settings', [SettingsController::class, 'index'],  'settings.view');
    $r->add('PUT', '/settings', [SettingsController::class, 'update'], 'settings.manage');
    $r->add('GET', '/settings/registration-number', [SettingsController::class, 'registrationNumber'],       'settings.view');
    $r->add('PUT', '/settings/registration-number', [SettingsController::class, 'updateRegistrationNumber'], 'settings.manage');
    $r->add('PUT', '/settings/paper-file-number',   [SettingsController::class, 'updatePaperMax'],            'settings.manage');

    // Patients
    $r->add('GET',  '/patients',                          [PatientController::class, 'index'],    'patients.view');
    $r->add('POST', '/patients',                          [PatientController::class, 'store'],    'patients.create');
    $r->add('GET',  '/patients/{id}',                     [PatientController::class, 'show'],     'patients.view');
    $r->add('PUT',  '/patients/{id}',                     [PatientController::class, 'update'],   'patients.update');
    $r->add('PUT',  '/patients/{id}/file-number',         [PatientController::class, 'correctFileNumber'], 'patients.number.correct');
    $r->add('GET',  '/patients/{id}/files',               [PatientController::class, 'files'],    'patients.files.view');
    $r->add('POST', '/patients/{id}/files',               [PatientController::class, 'upload'],   'patients.files.upload');
    $r->add('GET',  '/patients/{id}/files/{fileId}',      [PatientController::class, 'download'], 'patients.files.view');
    $r->add('POST', '/patients/{id}/files/{fileId}/void', [PatientController::class, 'void'],     'patients.files.upload');

    // Visits
    $r->add('GET',  '/visits',             [VisitController::class, 'index'],   'visits.view');
    $r->add('GET',  '/visits/summary',     [VisitController::class, 'summary'], 'visits.view');
    $r->add('GET',  '/visits/options',     [VisitController::class, 'options'], 'visits.create');
    $r->add('GET',  '/visits/quote',       [VisitController::class, 'quote'],   'visits.create');
    $r->add('POST', '/visits',             [VisitController::class, 'store'],   'visits.create');
    $r->add('GET',  '/visits/{id}',        [VisitController::class, 'show'],    'visits.view');
    $r->add('POST', '/visits/{id}/cancel', [VisitController::class, 'cancel'],  'visits.cancel');
    $r->add('POST', '/visits/{id}/end',    [VisitController::class, 'end'],     'visits.end');
    $r->add('POST', '/visits/{id}/readmit', [VisitController::class, 'readmit'], 'visits.readmit');
    $r->add('GET',  '/visits/{id}/fast-track', [VisitController::class, 'fastTrackQuote'], 'payments.fast_track');
    $r->add('POST', '/visits/{id}/fast-track', [VisitController::class, 'fastTrack'],      'payments.fast_track');

    // Cashier
    $r->add('GET',  '/bills',                       [BillController::class, 'index'],           'payments.view');
    $r->add('GET',  '/bills/history',               [BillController::class, 'history'],         'payments.history');
    $r->add('GET',  '/bills/{id}',                  [BillController::class, 'show'],            Router::AUTH);
    $r->add('POST', '/bills/{id}/pay',              [BillController::class, 'pay'],             'payments.process');
    $r->add('POST', '/bills/{id}/discount-request', [BillController::class, 'discountRequest'], 'payments.discount.request');
    $r->add('POST', '/bills/{id}/cancel-request',   [BillController::class, 'cancelRequest'],   'payments.cancel.request');
    $r->add('GET',  '/receipts/{id}',               [BillController::class, 'receipt'],         Router::AUTH);

    // Refunds
    $r->add('GET',  '/refunds',             [RefundController::class, 'index'],    'refunds.view');
    $r->add('GET',  '/refunds/eligible',    [RefundController::class, 'eligible'], 'refunds.request');
    $r->add('POST', '/refunds',             [RefundController::class, 'store'],    'refunds.request');
    $r->add('POST', '/refunds/{id}/payout', [RefundController::class, 'payout'],   'refunds.payout');

    // Approvals
    $r->add('GET',  '/approvals',                        [ApprovalController::class, 'index'],        'approvals.view');
    // Polled for the sidebar badge: must not count as user activity.
    $r->add('GET',  '/approvals/count',                  [ApprovalController::class, 'count'],        'approvals.view', touch: false);
    $r->add('GET',  '/approvals/{id}',                   [ApprovalController::class, 'show'],         'approvals.view');
    $r->add('POST', '/approvals/{id}/approve',           [ApprovalController::class, 'approve'],      'approvals.decide');
    $r->add('POST', '/approvals/{id}/reject',            [ApprovalController::class, 'reject'],       'approvals.decide');
    // Any discount requester (cashier, optical, diagnostics): the controller checks the permissions and that it is their own pending request.
    $r->add('POST', '/approvals/{id}/files',             [ApprovalController::class, 'uploadFile'],   Router::AUTH);
    $r->add('GET',  '/approvals/{id}/files/{fileId}',    [ApprovalController::class, 'downloadFile'], 'approvals.view');
    // Documents from a patient's earlier discount requests, for discount requesters and approvers (checked in the controller).
    $r->add('GET',  '/patients/{id}/discount-documents',          [ApprovalController::class, 'patientDocuments'],        Router::AUTH);
    $r->add('GET',  '/patients/{id}/discount-documents/{fileId}', [ApprovalController::class, 'downloadPatientDocument'], Router::AUTH);

    // Clinical — shared
    $r->add('GET', '/clinical/options',               [ClinicalController::class, 'options'], Router::AUTH);
    $r->add('GET', '/clinical/patients/{id}/history', [ClinicalController::class, 'history'], Router::AUTH);
    // Test results for doctors: opened from the consultation, patient history and the bell (polled)
    $r->add('GET', '/clinical/results/unseen',         [ClinicalController::class, 'unseenResults'], 'ophthalmology.results.view', touch: false);
    $r->add('GET', '/clinical/results/{id}',           [ClinicalController::class, 'result'], 'ophthalmology.results.view');
    $r->add('GET', '/clinical/results/{id}/files/{fileId}', [ClinicalController::class, 'resultFile'], 'ophthalmology.results.view');

    // Triage, Optometry, Ophthalmology — the same workflow endpoints for each stage
    foreach (['triage' => TriageController::class, 'optometry' => OptometryController::class, 'ophthalmology' => OphthalmologyController::class] as $stage => $controller) {
        // The queue is polled while the screen is open: must not count as user activity.
        $r->add('GET',  "/$stage/queue",                [$controller, 'queue'],    "$stage.view", touch: false);
        $r->add('GET',  "/$stage/recent",               [$controller, 'recent'],   "$stage.record");
        $r->add('GET',  "/$stage/visits/{id}",          [$controller, 'show'],     "$stage.view");
        $r->add('POST', "/$stage/visits/{id}/claim",    [$controller, 'claim'],    "$stage.record");
        $r->add('POST', "/$stage/visits/{id}/release",  [$controller, 'release'],  "$stage.record");
        $r->add('PUT',  "/$stage/visits/{id}",          [$controller, 'save'],     "$stage.record");
        $r->add('POST', "/$stage/visits/{id}/complete", [$controller, 'complete'], "$stage.record");
        $r->add('POST', "/$stage/visits/{id}/addenda",  [$controller, 'addendum'], "$stage.record");
    }
    // The issuing doctor may change an issued prescription for a while (theatre prescriptions excluded for now)
    $r->add('PUT', '/optometry/visits/{id}/prescription',     [OptometryController::class, 'amendPrescription'], 'optometry.record');
    $r->add('PUT', '/ophthalmology/visits/{id}/prescription', [OphthalmologyController::class, 'amendPrescription'], 'ophthalmology.record');

    // Follow-ups booked by clinicians, listed at reception
    $r->add('GET',  '/follow-ups',             [ClinicalController::class, 'followUps'],      'visits.followups.view');
    $r->add('POST', '/follow-ups/{id}/cancel', [ClinicalController::class, 'cancelFollowUp'], 'visits.followups.manage');

    // Diagnostics — booking desk, reporting queue, history
    $r->add('GET',  '/diagnostics/options',                    [DiagnosticController::class, 'options'],             'diagnostics.booking.view');
    // The desk list is polled while the screen is open: must not count as user activity.
    $r->add('GET',  '/diagnostics/bookings',                   [DiagnosticController::class, 'index'],               'diagnostics.booking.view', touch: false);
    $r->add('POST', '/diagnostics/bookings',                   [DiagnosticController::class, 'store'],               'diagnostics.booking.manage');
    $r->add('GET',  '/diagnostics/bookings/{id}',              [DiagnosticController::class, 'show'],                'diagnostics.booking.view');
    $r->add('PUT',  '/diagnostics/bookings/{id}',              [DiagnosticController::class, 'update'],              'diagnostics.booking.manage');
    $r->add('POST', '/diagnostics/bookings/{id}/check-in',     [DiagnosticController::class, 'checkIn'],             'diagnostics.booking.checkin');
    $r->add('POST', '/diagnostics/bookings/{id}/cancel',       [DiagnosticController::class, 'requestCancellation'], 'diagnostics.booking.cancel');
    $r->add('PUT',  '/diagnostics/bookings/{id}/report',       [DiagnosticController::class, 'saveReport'],          'diagnostics.report.record');
    $r->add('POST', '/diagnostics/bookings/{id}/addenda',      [DiagnosticController::class, 'addAddendum'],         'diagnostics.report.record');
    $r->add('POST', '/diagnostics/bookings/{id}/files',        [DiagnosticController::class, 'uploadFile'],          'diagnostics.report.record');
    $r->add('GET',  '/diagnostics/bookings/{id}/files/{fileId}', [DiagnosticController::class, 'downloadFile'],      'diagnostics.history.view');
    $r->add('DELETE', '/diagnostics/bookings/{id}/files/{fileId}', [DiagnosticController::class, 'removeFile'],     'diagnostics.report.record');
    $r->add('GET',  '/diagnostics/patients/{id}',              [DiagnosticController::class, 'patientHistory'],      'diagnostics.history.view');

    // Optical room — incoming prescriptions, orders, dispensing
    $r->add('GET',  '/optical/options',                  [OpticalController::class, 'options'],             'optical.view');
    $r->add('GET',  '/optical/incoming',                 [OpticalController::class, 'incoming'],            'optical.view', touch: false);
    $r->add('GET',  '/optical/prescriptions/{id}',       [OpticalController::class, 'prescription'],        'optical.view');
    $r->add('GET',  '/optical/orders',                   [OpticalController::class, 'index'],               'optical.view', touch: false);
    $r->add('POST', '/optical/orders',                   [OpticalController::class, 'store'],               'optical.orders.manage');
    $r->add('GET',  '/optical/orders/{id}',              [OpticalController::class, 'show'],                'optical.view');
    $r->add('PUT',  '/optical/orders/{id}',              [OpticalController::class, 'update'],              'optical.orders.manage');
    $r->add('POST', '/optical/orders/{id}/dispense',     [OpticalController::class, 'dispense'],            'optical.dispense');
    $r->add('POST', '/optical/orders/{id}/discount',     [OpticalController::class, 'requestDiscount'],     'optical.discount.request');
    $r->add('POST', '/optical/orders/{id}/cancel',       [OpticalController::class, 'requestCancellation'], 'optical.cancel.request');
    $r->add('GET',  '/optical/patients/{id}',            [OpticalController::class, 'patientOrders'],       'optical.view');
    $r->add('POST', '/optical/external',                 [OpticalController::class, 'storeExternal'],       'optical.orders.manage');
    $r->add('POST', '/optical/orders/{id}/change-frame', [OpticalController::class, 'changeFrame'],         'optical.orders.manage');
    $r->add('POST', '/optical/orders/{id}/lenses',       [OpticalController::class, 'changeLens'],          'optical.orders.manage');
    $r->add('POST', '/optical/referrals/{id}/close-waiting', [OpticalController::class, 'closeWaiting'],    'optical.orders.manage');
    $r->add('POST', '/optical/orders/{id}/extra-pair',   [OpticalController::class, 'extraPair'],           'optical.orders.manage');
    $r->add('GET',  '/optical/frames',                   [OpticalController::class, 'frames'],              'optical.view');
    $r->add('POST', '/optical/frames',                   [OpticalController::class, 'storeFrame'],          'optical.frames.manage');
    $r->add('PUT',  '/optical/frames/{id}',              [OpticalController::class, 'updateFrame'],         'optical.frames.manage');
    $r->add('POST', '/optical/frames/{id}/adjust',       [OpticalController::class, 'adjustFrame'],         'optical.frames.manage');
    $r->add('GET',  '/optical/frames/{id}/movements',    [OpticalController::class, 'frameMovements'],      'optical.view');

    // Pharmacy — orders, dispensing, walk-in sales and stock
    $r->add('GET',  '/pharmacy/options',                [PharmacyController::class, 'options'],         'pharmacy.orders.view');
    $r->add('GET',  '/pharmacy/orders',                 [PharmacyController::class, 'index'],           'pharmacy.orders.view', touch: false);
    $r->add('GET',  '/pharmacy/orders/{id}',            [PharmacyController::class, 'show'],            'pharmacy.orders.view');
    $r->add('POST', '/pharmacy/orders/{id}/review',     [PharmacyController::class, 'review'],          'pharmacy.orders.review');
    $r->add('POST', '/pharmacy/orders/{id}/dispense',   [PharmacyController::class, 'dispense'],        'pharmacy.dispense');
    $r->add('POST', '/pharmacy/orders/{id}/cancel',     [PharmacyController::class, 'cancelRemaining'], 'pharmacy.cancel');
    $r->add('POST', '/pharmacy/orders/{id}/balance',    [PharmacyController::class, 'supplyBalance'],   'pharmacy.orders.review');
    $r->add('POST', '/pharmacy/orders/{id}/refill',     [PharmacyController::class, 'refill'],          'pharmacy.dispense');
    $r->add('POST', '/pharmacy/orders/{id}/balance/close', [PharmacyController::class, 'closeBalance'], 'pharmacy.cancel');
    $r->add('POST', '/pharmacy/walk-in-sales',          [PharmacyController::class, 'walkInSale'],      'pharmacy.external_sale');
    $r->add('GET',  '/pharmacy/stock',                  [PharmacyController::class, 'stock'],           'pharmacy.inventory.view', touch: false);
    $r->add('GET',  '/pharmacy/stock/{id}/movements',   [PharmacyController::class, 'movements'],       'pharmacy.inventory.view');
    $r->add('POST', '/pharmacy/stock/{id}',             [PharmacyController::class, 'adjustStock'],     'pharmacy.stock.manage');
    $r->add('GET',  '/pharmacy/patients/{id}',          [PharmacyController::class, 'patientOrders'],   'pharmacy.orders.view');

    // Theatre — bookings, pre-op, the operation, post-op and history
    $r->add('GET',  '/theatre/options',                   [TheatreController::class, 'options'],             'theatre.booking.view');
    $r->add('GET',  '/theatre/patients/{id}/biometry',    [TheatreController::class, 'latestBiometry'],      'theatre.booking.manage');
    $r->add('GET',  '/theatre/recommendations',           [TheatreController::class, 'recommendations'],     'theatre.booking.view');
    $r->add('GET',  '/theatre/bookings',                  [TheatreController::class, 'index'],               'theatre.booking.view', touch: false);
    $r->add('POST', '/theatre/bookings',                  [TheatreController::class, 'store'],               'theatre.booking.manage');
    $r->add('GET',  '/theatre/bookings/{id}',             [TheatreController::class, 'show'],                'theatre.booking.view');
    $r->add('PUT',  '/theatre/bookings/{id}',             [TheatreController::class, 'update'],              'theatre.booking.manage');
    $r->add('POST', '/theatre/bookings/{id}/bill',        [TheatreController::class, 'raiseBill'],           'theatre.booking.manage');
    $r->add('POST', '/theatre/bookings/{id}/defer',       [TheatreController::class, 'deferPayment'],        'theatre.booking.defer_payment');
    $r->add('POST', '/theatre/bookings/{id}/cancel',      [TheatreController::class, 'requestCancellation'], 'theatre.booking.cancel');
    $r->add('PUT',  '/theatre/bookings/{id}/preop',       [TheatreController::class, 'savePreop'],           'theatre.preop.record');
    $r->add('POST', '/theatre/bookings/{id}/preop/hold',  [TheatreController::class, 'holdPreop'],           'theatre.preop.record');
    $r->add('PUT',  '/theatre/bookings/{id}/operation',   [TheatreController::class, 'saveOperation'],       'theatre.intraop.record');
    $r->add('PUT',  '/theatre/bookings/{id}/postop',      [TheatreController::class, 'savePostopVisit'],     'theatre.postop.record');
    $r->add('POST', '/theatre/bookings/{id}/discharge',   [TheatreController::class, 'discharge'],           'theatre.postop.record');
    $r->add('POST', '/theatre/bookings/{id}/addenda',     [TheatreController::class, 'addAddendum'],         'theatre.postop.record');
    $r->add('GET',  '/theatre/patients/{id}',             [TheatreController::class, 'patientCases'],        'theatre.history.view');

    // Stores — stock, requisitions, suppliers, quotations, purchase orders, goods in
    $r->add('GET',  '/stores/options',                 [StoreController::class, 'options'],            'stores.stock.view');
    $r->add('GET',  '/stores/requisition-options',     [StoreController::class, 'requisitionOptions'], 'requisitions.view_own');
    $r->add('GET',  '/stores/stock',                   [StoreController::class, 'stock'],              'stores.stock.view', touch: false);
    $r->add('POST', '/stores/stock',                   [StoreController::class, 'storeItem'],          'stores.stock.manage');
    $r->add('GET',  '/stores/stock/{id}',              [StoreController::class, 'showItem'],           'stores.stock.view');
    $r->add('PUT',  '/stores/stock/{id}',              [StoreController::class, 'updateItem'],         'stores.stock.manage');
    $r->add('POST', '/stores/stock/{id}/adjust',       [StoreController::class, 'adjustStock'],        'stores.stock.manage');

    $r->add('GET',  '/stores/requisitions',            [StoreController::class, 'requisitions'],       'requisitions.view_own', touch: false);
    $r->add('POST', '/stores/requisitions',            [StoreController::class, 'storeRequisition'],   'requisitions.create');
    $r->add('GET',  '/stores/requisitions/{id}',       [StoreController::class, 'showRequisition'],    'requisitions.view_own');
    $r->add('PUT',  '/stores/requisitions/{id}',       [StoreController::class, 'updateRequisition'],  'requisitions.create');
    $r->add('POST', '/stores/requisitions/{id}/decide', [StoreController::class, 'decideRequisition'], 'requisitions.manage');
    $r->add('POST', '/stores/requisitions/{id}/issue', [StoreController::class, 'issueRequisition'],   'requisitions.manage');

    $r->add('GET',  '/stores/suppliers',               [StoreController::class, 'suppliers'],          'stores.procurement.view');
    $r->add('POST', '/stores/suppliers',               [StoreController::class, 'storeSupplier'],      'stores.suppliers.manage');
    $r->add('PUT',  '/stores/suppliers/{id}',          [StoreController::class, 'updateSupplier'],     'stores.suppliers.manage');

    $r->add('GET',  '/stores/quotations',              [StoreController::class, 'quotations'],         'stores.procurement.view', touch: false);
    $r->add('POST', '/stores/quotations',              [StoreController::class, 'storeQuotation'],     'stores.quotations.manage');
    $r->add('GET',  '/stores/quotations/compare',      [StoreController::class, 'comparison'],         'stores.procurement.view');
    $r->add('GET',  '/stores/quotations/{id}',         [StoreController::class, 'showQuotation'],      'stores.procurement.view');
    $r->add('PUT',  '/stores/quotations/{id}',         [StoreController::class, 'updateQuotation'],    'stores.quotations.manage');
    $r->add('POST', '/stores/quotations/{id}/select',  [StoreController::class, 'selectQuotation'],    'stores.quotations.manage');

    $r->add('GET',  '/stores/orders',                  [StoreController::class, 'orders'],             'stores.procurement.view', touch: false);
    $r->add('POST', '/stores/orders',                  [StoreController::class, 'storeOrder'],         'stores.lpo.manage');
    $r->add('GET',  '/stores/orders/{id}',             [StoreController::class, 'showOrder'],          'stores.procurement.view');
    $r->add('POST', '/stores/orders/{id}/approve',     [StoreController::class, 'approveOrder'],       'stores.procurement.view');
    $r->add('POST', '/stores/orders/{id}/send',        [StoreController::class, 'sendOrder'],          'stores.lpo.manage');
    $r->add('POST', '/stores/orders/{id}/cancel',      [StoreController::class, 'cancelOrder'],        'stores.lpo.manage');

    $r->add('GET',  '/stores/deliveries',              [StoreController::class, 'deliveries'],         'stores.grn.view', touch: false);
    $r->add('POST', '/stores/deliveries',              [StoreController::class, 'storeDelivery'],      'stores.grn.manage');
    $r->add('POST', '/stores/deliveries/{id}/decide',  [StoreController::class, 'decideDelivery'],     'stores.grn.approve');

    // Dashboard and reports
    $r->add('GET', '/dashboard',             [DashboardController::class, 'index'], 'dashboard.view', touch: false);
    // Each report checks its own permission (ReportService::REPORTS), so someone allowed only the
    // MTUHA registers can open those without the general report terminal.
    $r->add('GET', '/reports/options',       [ReportController::class, 'options'],  Router::AUTH);
    // The report type travels as a query parameter: route placeholders are numeric ids.
    $r->add('GET', '/reports/run',           [ReportController::class, 'run'],      Router::AUTH);
    $r->add('GET', '/reports/export',        [ReportController::class, 'export'],   Router::AUTH);

    // Clinical catalogues
    $r->add('GET',  '/catalogues/icd10',         [CatalogueController::class, 'icdIndex'],      'catalogues.manage');
    $r->add('POST', '/catalogues/icd10',         [CatalogueController::class, 'icdStore'],      'catalogues.manage');
    $r->add('PUT',  '/catalogues/icd10/{id}',    [CatalogueController::class, 'icdUpdate'],     'catalogues.manage');
    $r->add('GET',  '/catalogues/drugs',         [CatalogueController::class, 'drugIndex'],     'catalogues.manage');
    $r->add('POST', '/catalogues/drugs',         [CatalogueController::class, 'drugStore'],     'catalogues.manage');
    $r->add('PUT',  '/catalogues/drugs/{id}',    [CatalogueController::class, 'drugUpdate'],    'catalogues.manage');
    $r->add('GET',  '/catalogues/findings',      [CatalogueController::class, 'findingIndex'],  'catalogues.manage');
    $r->add('POST', '/catalogues/findings',      [CatalogueController::class, 'findingStore'],  'catalogues.manage');
    $r->add('PUT',  '/catalogues/findings/{id}', [CatalogueController::class, 'findingUpdate'], 'catalogues.manage');
    $r->add('GET',  '/catalogues/lists',         [CatalogueController::class, 'listIndex'],     'catalogues.manage');
    $r->add('POST', '/catalogues/lists',         [CatalogueController::class, 'listStore'],     'catalogues.manage');
    $r->add('PUT',  '/catalogues/lists/{id}',    [CatalogueController::class, 'listUpdate'],    'catalogues.manage');
    $r->add('GET',  '/catalogues/operation-templates',      [CatalogueController::class, 'templateIndex'], 'catalogues.manage');
    $r->add('PUT',  '/catalogues/operation-templates/{id}', [CatalogueController::class, 'templateSave'],  'catalogues.manage');

    // Price list
    $r->add('GET',  '/prices',              [PriceController::class, 'index'],   'prices.view');
    $r->add('POST', '/prices',              [PriceController::class, 'store'],   'prices.manage');
    $r->add('PUT',  '/prices/{id}',         [PriceController::class, 'update'],  'prices.manage');
    $r->add('GET',  '/prices/{id}/history', [PriceController::class, 'history'], 'prices.view');

    // Audit
    $r->add('GET', '/audit',         [AuditController::class, 'index'],     'audit.view');
    $r->add('GET', '/audit/filters', [AuditController::class, 'filters'],   'audit.view');
    $r->add('GET', '/access-log',    [AuditController::class, 'accessLog'], 'access_log.view');
};
