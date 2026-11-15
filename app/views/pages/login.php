<?php
defined('KSI_APP') || exit;

use App\Core\Auth;
use App\Core\Settings;

/** @var ?App\Core\AuthContext $ctx */
$mode = $ctx !== null ? 'change_password' : 'login';
$notice = match (true) {
    Auth::sessionExpired() || isset($_GET['expired']) => 'Your session ended because of inactivity. Please sign in again.',
    isset($_GET['signed_out']) => 'You have been signed out.',
    default => '',
};
$boot = [
    'basePath' => Auth::basePath(),
    'mode' => $mode,
    'csrf' => $ctx !== null ? Auth::csrfToken($ctx->rawToken) : null,
    'passwordMinLength' => max(8, Settings::int('security.password_min_length', 10)),
];
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = KSI_ASSET_VERSION;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Sign in — KSICEC HMS</title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png?v=<?= $v ?>">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/responsive.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/app-ext.css?v=<?= $v ?>">
</head>
<body>
<div id="login-overlay">
  <div class="login-hero">
    <div class="login-hero-logo">KSI</div>
    <div class="login-hero-name">KSICEC HMS</div>
    <div class="login-hero-sub">Hospital Management System</div>
  </div>

  <div class="login-card">
    <!-- Sign in -->
    <form class="auth-view<?= $mode === 'login' ? '' : ' hidden' ?>" id="view-login" novalidate>
      <div class="login-logo">KSI</div>
      <div class="login-title">KSICEC HMS</div>
      <div class="login-sub">Sign in to your account</div>
      <div class="login-notice<?= $notice ? ' show' : '' ?>" id="login-notice" role="status"><?= $e($notice) ?></div>
      <div class="login-field">
        <label for="login-username">Username</label>
        <input type="text" id="login-username" name="username" placeholder="Enter username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required>
      </div>
      <div class="login-field">
        <label for="login-password">Password</label>
        <input type="password" id="login-password" name="password" placeholder="Enter password" autocomplete="current-password" maxlength="128" required>
      </div>
      <div class="login-error" id="login-error" role="alert"></div>
      <button type="submit" class="login-btn" id="login-submit">Sign In</button>
      <div class="login-help">Forgot your password or need an account? Contact the system administrator.</div>
      <div class="login-footer">KSI CEC &copy; <?= date('Y') ?> &mdash; Authorised access only</div>
    </form>

    <!-- Temporary password must be replaced -->
    <form class="auth-view<?= $mode === 'change_password' ? '' : ' hidden' ?>" id="view-change-password" novalidate>
      <div class="login-logo">KSI</div>
      <div class="login-title">Set a new password</div>
      <div class="login-sub">
        <?= $ctx !== null ? 'Hello ' . $e($ctx->displayName) . ', your' : 'Your' ?> password was set by an administrator. Choose your own password to continue.
      </div>
      <div class="login-field">
        <label for="cp-current">Current (temporary) password</label>
        <input type="password" id="cp-current" autocomplete="current-password" maxlength="128" required>
      </div>
      <div class="login-field">
        <label for="cp-new">New password</label>
        <input type="password" id="cp-new" autocomplete="new-password" maxlength="128" required>
        <div class="field-hint">At least <?= (int) $boot['passwordMinLength'] ?> characters, with a letter and a number, not containing your username.</div>
      </div>
      <div class="login-field">
        <label for="cp-confirm">Repeat new password</label>
        <input type="password" id="cp-confirm" autocomplete="new-password" maxlength="128" required>
      </div>
      <div class="login-error" id="change-error" role="alert"></div>
      <button type="submit" class="login-btn" id="change-submit">Save and continue</button>
      <button type="button" class="auth-link-btn" id="change-cancel">Sign out</button>
    </form>
  </div>
</div>
<script type="application/json" id="ksi-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script src="assets/js/core/login.js?v=<?= $v ?>"></script>
</body>
</html>
