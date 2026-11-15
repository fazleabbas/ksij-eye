<?php
declare(strict_types=1);

namespace App\Core\Http;

/** Immutable view of the incoming API request. Only JSON bodies are accepted. */
final class Request
{
    private const MAX_BODY_BYTES = 1048576;

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $cookies,
        private readonly array $server,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uriPath = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
        $apiBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');
        $path = stripos($uriPath, $apiBase) === 0 ? substr($uriPath, strlen($apiBase)) : $uriPath;
        $path = '/' . trim($path, '/');
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new HttpException(400, 'bad_request', 'Invalid request path.');
        }

        $body = [];
        $files = [];
        $isMultipart = str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
        if ($isMultipart && $method === 'POST') {
            // File uploads: PHP has already parsed the form within post_max_size / upload_max_filesize.
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::iniBytes((string) ini_get('post_max_size'))) {
                throw new HttpException(413, 'payload_too_large', 'The upload is too large.');
            }
            $body = $_POST;
            $files = $_FILES;
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
                throw new HttpException(413, 'payload_too_large', 'Request body is too large.');
            }
            $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                throw new HttpException(413, 'payload_too_large', 'Request body is too large.');
            }
            if ($raw !== '') {
                if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
                    throw new HttpException(415, 'unsupported_media_type', 'Requests must be sent as JSON.');
                }
                try {
                    $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new HttpException(400, 'invalid_json', 'Malformed JSON body.');
                }
                if (!is_array($decoded)) {
                    throw new HttpException(400, 'invalid_json', 'JSON body must be an object.');
                }
                $body = $decoded;
            }
        }

        return new self(
            $method,
            $path,
            $_GET,
            $body,
            $files,
            $_COOKIE,
            $_SERVER,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        );
    }

    public function all(): array
    {
        return $this->body;
    }

    /** One uploaded file from a multipart request ($_FILES entry), or null. */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;
        return is_array($file) ? $file : null;
    }

    private static function iniBytes(string $value): int
    {
        $number = (int) $value;
        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function page(): int
    {
        return max(1, (int) $this->query('page', '1'));
    }

    public function perPage(int $default = 50, int $max = 200): int
    {
        return min(max(1, (int) $this->query('per_page', (string) $default)), $max);
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function header(string $name): ?string
    {
        $value = $this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
        return is_string($value) ? $value : null;
    }

    /** Refuses state-changing requests that a browser marks as coming from another site. */
    public function assertSameOrigin(): void
    {
        $source = $this->header('Origin') ?: $this->header('Referer');
        if (!$source) {
            return; // non-browser client; authenticated writes still need the CSRF token
        }
        $host = parse_url($source, PHP_URL_HOST);
        $port = parse_url($source, PHP_URL_PORT);
        $origin = $host . ($port ? ':' . $port : '');
        if (!$host || strcasecmp($origin, (string) ($this->server['HTTP_HOST'] ?? '')) !== 0) {
            throw new HttpException(403, 'bad_origin', 'Cross-origin request refused.');
        }
    }
}
