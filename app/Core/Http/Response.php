<?php
declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    public static function ok(mixed $data, int $status = 200): never
    {
        self::json($status, ['ok' => true, 'data' => $data]);
    }

    public static function error(int $status, string $code, string $message, array $fields = []): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields) {
            $error['fields'] = $fields;
        }
        self::json($status, ['ok' => false, 'error' => $error]);
    }

    public static function json(int $status, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header_remove('X-Powered-By');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** Sends a (decrypted) file. Never cached; sandboxed so a malicious upload cannot run script. */
    public static function file(string $content, string $mimeType, string $filename, bool $inline = false): never
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'file';
        http_response_code(200);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . strlen($content));
        header(sprintf("Content-Disposition: %s; filename=\"%s\"; filename*=UTF-8''%s", $inline ? 'inline' : 'attachment', $ascii, rawurlencode($filename)));
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        header_remove('X-Powered-By');
        echo $content;
        exit;
    }

    /** Headers for HTML pages: never cached, framing refused, scripts/connections limited to this origin. */
    public static function pageHeaders(bool $strictScripts): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header_remove('X-Powered-By');
        // The legacy screens still use inline event handlers, so 'unsafe-inline' is needed
        // there until they are refactored; the sign-in page runs with no inline script at all.
        $scriptSrc = $strictScripts ? "'self'" : "'self' 'unsafe-inline'";
        header("Content-Security-Policy: default-src 'self'; script-src $scriptSrc; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self' blob: data:; "
            . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}
