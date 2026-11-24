<?php
/**
 * Page entry. The server decides what to render from the session cookie:
 *  - no valid session            → sign-in page (no application markup or data is sent)
 *  - temporary password pending  → change-password page
 *  - signed in                   → application shell
 */
declare(strict_types=1);

define('KSI_APP', true);
const KSI_ASSET_VERSION = '0.25.0';

use App\Core\Auth;
use App\Core\Http\Response;

try {
    require __DIR__ . '/app/bootstrap.php';
    $cookie = $_COOKIE[Auth::cookieName()] ?? null;
    $ctx = Auth::authenticate(is_string($cookie) ? $cookie : null, true);
} catch (\Throwable $e) {
    error_log('[page] ' . $e::class . ': ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit('KSICEC HMS is temporarily unavailable. Please contact the system administrator.');
}

if ($ctx === null || $ctx->mustChangePassword) {
    Response::pageHeaders(strictScripts: true);
    require __DIR__ . '/app/views/pages/login.php';
} else {
    Response::pageHeaders(strictScripts: false);
    require __DIR__ . '/app/views/pages/app.php';
}
