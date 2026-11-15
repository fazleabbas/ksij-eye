<?php
defined('KSI_APP') || exit;

use App\Core\Auth;

/** @var App\Core\AuthContext $ctx */
$boot = Auth::clientPayload($ctx) + ['basePath' => Auth::basePath()];
$v = KSI_ASSET_VERSION;
$view = static fn (string $name): string => dirname(__DIR__) . '/' . $name . '.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>KSICEC HMS — Hospital Management System</title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png?v=<?= $v ?>">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/responsive.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/app-ext.css?v=<?= $v ?>">
</head>
<body>
<script type="application/json" id="ksi-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<?php require $view('layout/topnav'); ?>
<?php require $view('layout/sidebar'); ?>
<?php require $view('modules/dashboard'); ?>
<?php require $view('modules/patients'); ?>
<?php require $view('modules/cashier'); ?>
<?php require $view('modules/approvals'); ?>
<?php require $view('modules/triage'); ?>
<?php require $view('modules/optometry'); ?>
<?php require $view('modules/ophthalmology'); ?>
<?php require $view('modules/patient-history'); ?>
<?php require $view('modules/theatre'); ?>
<?php require $view('modules/diagnostics'); ?>
<?php require $view('modules/optical'); ?>
<?php require $view('modules/pharmacy'); ?>
<?php require $view('modules/stores'); ?>
<?php require $view('modules/administration'); ?>
<?php require $view('modules/reports'); ?>
  </main>
</div>
<?php require $view('modals/reception'); ?>
<?php require $view('modals/billing'); ?>
<?php require $view('modals/approval-decisions'); ?>
<?php require $view('modals/clinical'); ?>
<?php require $view('modals/diagnostics'); ?>
<?php require $view('modals/optical'); ?>
<?php require $view('modals/pharmacy'); ?>
<?php require $view('modals/theatre'); ?>
<?php require $view('modals/account'); ?>

<!-- ══ JAVASCRIPT ══ -->
<script src="assets/js/core/api.js?v=<?= $v ?>"></script>
<script src="assets/js/core/modals.js?v=<?= $v ?>"></script>
<script src="assets/js/core/app-core.js?v=<?= $v ?>"></script>
<script src="assets/js/shared/ui.js?v=<?= $v ?>"></script>
<script src="assets/js/shared/searchable-select.js?v=<?= $v ?>"></script>
<script src="assets/js/shared/clinical.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/triage.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/optometry.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/ophthalmology.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/patient-history.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/reception.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/billing.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/refunds.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/approvals-queue.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/optical.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/pharmacy.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/theatre.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/reports.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/diagnostics.js?v=<?= $v ?>"></script>
<script src="assets/js/core/router.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/stores.js?v=<?= $v ?>"></script>
<script src="assets/js/core/nav.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/admin-users.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/admin-settings.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/admin-catalogues.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/admin-prices.js?v=<?= $v ?>"></script>
<script src="assets/js/modules/admin-audit.js?v=<?= $v ?>"></script>
<script src="assets/js/core/session.js?v=<?= $v ?>"></script>
<script src="assets/js/core/start.js?v=<?= $v ?>"></script>
<?php require $view('modals/stores'); ?>
<?php require $view('modals/administration'); ?>
<div class="print-credit">Powered by Fabonic</div>

</body>
</html>
