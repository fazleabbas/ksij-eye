<?php
defined('KSI_APP') || exit;

/** @var App\Core\AuthContext $ctx */
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$roleNames = implode(', ', array_column($ctx->roles, 'name')) ?: 'No role assigned';
?>

<!-- ══ TOP NAV ══════════════════════════════════════════════════════════ -->
<nav id="topnav">
  <div id="topnav-left">
    <button id="sidebar-toggle" title="Toggle Sidebar" aria-label="Toggle navigation">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div id="topnav-brand">
      <img class="brand-logo" src="assets/img/logo.png?v=<?= KSI_ASSET_VERSION ?>" alt="KSI Charitable Eye Centre" width="218" height="36">
    </div>
  </div>
  <div id="topnav-right">
    <div class="nav-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span id="live-date"></span>
    </div>
    <div class="nav-pill">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <span id="live-clock">00:00:00</span>
    </div>
    <div style="position:relative;">
      <button class="nav-icon-btn" id="notif-btn" title="Notifications" aria-label="Notifications">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span class="notif-badge" id="notif-count" style="display:none"></span>
      </button>
      <div id="notif-dropdown">
        <div class="notif-header">Notifications</div>
        <div id="notif-list"><div class="notif-item"><div class="notif-item-text">No new notifications.</div></div></div>
      </div>
    </div>
    <div style="position:relative;">
      <div class="user-chip" id="user-chip" role="button" tabindex="0" aria-haspopup="menu">
        <div class="user-avatar" id="user-avatar-initials"><?= $e($ctx->initials) ?></div>
        <div class="user-chip-info">
          <span class="user-chip-name" id="current-user-name"><?= $e($ctx->displayName) ?></span>
          <span class="user-chip-role" id="current-user-role"><?= $e($roleNames) ?></span>
        </div>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-3);margin-right:2px"><path d="m6 9 6 6 6-6"/></svg>
      </div>
      <div class="user-dropdown" id="user-dropdown" role="menu">
        <div class="user-menu-header">
          <div class="user-menu-name"><?= $e($ctx->displayName) ?></div>
          <div class="user-menu-meta"><?= $e($ctx->username) ?><?= $ctx->departmentName ? ' · ' . $e($ctx->departmentName) : '' ?></div>
          <div class="user-menu-meta"><?= $e($roleNames) ?></div>
        </div>
        <div class="user-dropdown-item" role="menuitem" onclick="openChangePassword()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Change Password
        </div>
        <div class="user-dropdown-item" role="menuitem" onclick="doLogout()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign Out
        </div>
      </div>
    </div>
  </div>
</nav>

