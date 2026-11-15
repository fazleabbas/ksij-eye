<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;
use App\Core\Http\Request;

/**
 * Database-backed sessions and sign-in.
 *  - The cookie holds a random 256-bit token; only its SHA-256 hash is stored.
 *  - Idle and absolute timeouts are enforced server-side on every request.
 *  - The CSRF token is an HMAC of the session token (nothing extra stored) sent as X-CSRF-Token.
 *  - Failed sign-ins lock the account after N attempts; a single IP is throttled as well.
 */
final class Auth
{
    private const IP_FAILURE_LIMIT = 30;
    private const COMMON_PASSWORDS = [
        'password1', 'password12', 'password123', 'passw0rd123', '1234567890a', 'qwerty12345', 'admin12345',
        'welcome123', 'letmein123', 'hospital123', 'ksieye1234', 'abc12345678', 'iloveyou123',
    ];

    private static ?AuthContext $current = null;
    private static bool $expired = false;
    private static ?string $dummyHash = null;

    public static function cookieName(): string
    {
        return (string) Config::get('session.name', 'KSIEYE_SID');
    }

    public static function user(): ?AuthContext
    {
        return self::$current;
    }

    public static function require(): AuthContext
    {
        return self::$current ?? throw new HttpException(401, 'unauthenticated', 'Please sign in.');
    }

    /** For endpoints shared by several screens: at least one of the permissions is required. */
    public static function requireAny(string ...$permissions): AuthContext
    {
        $ctx = self::require();
        foreach ($permissions as $permission) {
            if ($ctx->can($permission)) {
                return $ctx;
            }
        }
        throw new HttpException(403, 'forbidden', 'You do not have permission to perform this action.');
    }

    public static function sessionExpired(): bool
    {
        return self::$expired;
    }

    public static function idleSeconds(): int
    {
        return max(300, Settings::int('security.session_idle_minutes', 30) * 60);
    }

    public static function warningSeconds(): int
    {
        return max(15, min(Settings::int('security.session_warning_seconds', 120), self::idleSeconds() - 60));
    }

    public static function absoluteSeconds(): int
    {
        return max(3600, Settings::int('security.session_absolute_hours', 12) * 3600);
    }

    // ─── Sign-in ─────────────────────────────────────────────────────────

    /** @return array{must_change_password: bool} */
    public static function login(string $username, string $password, Request $request): array
    {
        $maxAttempts = Settings::int('security.login_max_attempts', 5);
        $lockMinutes = Settings::int('security.lockout_minutes', 15);

        $ipFailures = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = :ip AND success = 0 AND attempted_at > UTC_TIMESTAMP(3) - INTERVAL 15 MINUTE',
            ['ip' => $request->ip]
        );
        if ($ipFailures >= self::IP_FAILURE_LIMIT) {
            self::recordAttempt($username, null, $request, false, 'ip_throttled');
            throw new HttpException(429, 'too_many_attempts', 'Too many failed sign-in attempts from this device. Please wait 15 minutes.');
        }

        $user = Database::one(
            'SELECT id, username, first_name, middle_name, last_name, password_hash, status,
                    (locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP(3)) AS is_locked
               FROM users WHERE username = :u',
            ['u' => $username]
        );
        $invalid = new HttpException(401, 'invalid_credentials', 'Incorrect username or password.');

        if ($user === null) {
            password_verify($password, self::dummyHash()); // same work as a real check
            self::recordAttempt($username, null, $request, false, 'unknown_user');
            throw $invalid;
        }

        $userId = (int) $user['id'];
        $actor = ['user_id' => $userId, 'username' => $user['username'], 'display' => Format::fullName($user['first_name'], $user['middle_name'], $user['last_name'])];
        $passwordOk = password_verify($password, $user['password_hash']);

        if ((int) $user['is_locked'] === 1) {
            self::recordAttempt($username, $userId, $request, false, 'locked');
            throw new HttpException(423, 'account_locked', 'This account is temporarily locked after repeated failed sign-ins. Try again later or contact the administrator.');
        }

        if (!$passwordOk) {
            // MariaDB evaluates SET assignments left to right: locked_until reads the old counter.
            Database::execute(
                'UPDATE users
                    SET locked_until = IF(failed_login_count + 1 >= :max1, UTC_TIMESTAMP(3) + INTERVAL :mins MINUTE, locked_until),
                        failed_login_count = IF(failed_login_count + 1 >= :max2, 0, failed_login_count + 1)
                  WHERE id = :id',
                ['max1' => $maxAttempts, 'max2' => $maxAttempts, 'mins' => $lockMinutes, 'id' => $userId]
            );
            self::recordAttempt($username, $userId, $request, false, 'bad_password');
            Audit::log('auth', 'login.failed', 'Failed sign-in (wrong password)', 'user', $userId, null, $actor);
            $nowLocked = (int) Database::value('SELECT locked_until IS NOT NULL AND locked_until > UTC_TIMESTAMP(3) FROM users WHERE id = :id', ['id' => $userId]);
            if ($nowLocked === 1) {
                Audit::log('auth', 'account.locked', "Account locked for $lockMinutes minutes after $maxAttempts failed sign-ins", 'user', $userId, null, $actor);
            }
            throw $invalid;
        }

        if ($user['status'] !== 'active') {
            self::recordAttempt($username, $userId, $request, false, 'inactive');
            throw new HttpException(403, 'account_inactive', 'This account is inactive. Please contact the administrator.');
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Database::execute('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => self::hashPassword($password), 'id' => $userId]);
        }
        Database::execute(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP(3), last_login_ip = :ip WHERE id = :id',
            ['ip' => $request->ip, 'id' => $userId]
        );

        $token = random_bytes(32);
        Database::execute(
            'INSERT INTO user_sessions (token_hash, user_id, ip_address, user_agent, created_at, last_activity_at, expires_at)
             VALUES (:h, :u, :ip, :ua, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3) + INTERVAL :abs SECOND)',
            ['h' => hash('sha256', $token, true), 'u' => $userId, 'ip' => $request->ip, 'ua' => $request->userAgent, 'abs' => self::absoluteSeconds()]
        );
        self::setCookie(Crypto::base64url($token));
        self::recordAttempt($username, $userId, $request, true, 'ok');

        $ctx = self::authenticate(Crypto::base64url($token), false);
        Audit::log('auth', 'login', 'Signed in');

        return ['must_change_password' => (bool) $ctx?->mustChangePassword];
    }

    // ─── Session validation ──────────────────────────────────────────────

    public static function authenticate(?string $cookie, bool $touch = true): ?AuthContext
    {
        self::$current = null;
        if ($cookie === null || $cookie === '') {
            return null;
        }
        $token = Crypto::base64urlDecode($cookie);
        if ($token === null || strlen($token) !== 32) {
            return null;
        }

        $row = Database::one(
            'SELECT s.id AS session_id, s.revoked_at,
                    TIMESTAMPDIFF(SECOND, s.last_activity_at, UTC_TIMESTAMP(3)) AS idle_for,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), s.expires_at) AS until_absolute,
                    u.id AS user_id, u.username, u.first_name, u.middle_name, u.last_name, u.status,
                    u.must_change_password, u.department_id, d.name AS department_name
               FROM user_sessions s
               JOIN users u ON u.id = s.user_id
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE s.token_hash = :h',
            ['h' => hash('sha256', $token, true)]
        );
        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }

        $sessionId = (int) $row['session_id'];
        $idleLimit = self::idleSeconds();
        $reason = match (true) {
            (int) $row['until_absolute'] <= 0   => 'absolute_timeout',
            (int) $row['idle_for'] >= $idleLimit => 'idle_timeout',
            $row['status'] !== 'active'          => 'user_inactive',
            default                              => null,
        };
        if ($reason !== null) {
            $revoked = Database::execute(
                'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(3), revoked_reason = :r WHERE id = :id AND revoked_at IS NULL',
                ['r' => $reason, 'id' => $sessionId]
            );
            if ($revoked > 0) {
                Audit::log('auth', 'session.ended', "Session ended ($reason)", 'user', (int) $row['user_id'], null, [
                    'user_id' => (int) $row['user_id'], 'username' => $row['username'],
                    'display' => Format::fullName($row['first_name'], $row['middle_name'], $row['last_name']),
                ]);
            }
            self::$expired = true;
            return null;
        }

        $idleFor = (int) $row['idle_for'];
        if ($touch && $idleFor >= 5) {
            Database::execute('UPDATE user_sessions SET last_activity_at = UTC_TIMESTAMP(3) WHERE id = :id', ['id' => $sessionId]);
            $idleFor = 0;
        }

        $userId = (int) $row['user_id'];
        $roles = array_map(
            static fn (array $r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'color' => $r['color']],
            Database::all('SELECT r.id, r.code, r.name, r.color FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :u ORDER BY r.name', ['u' => $userId])
        );
        $permissions = array_column(Database::all(
            'SELECT DISTINCT p.code FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = :u ORDER BY p.code',
            ['u' => $userId]
        ), 'code');

        return self::$current = new AuthContext(
            userId: $userId,
            sessionId: $sessionId,
            username: $row['username'],
            displayName: Format::fullName($row['first_name'], $row['middle_name'], $row['last_name']),
            initials: Format::initials($row['first_name'], $row['last_name']),
            departmentId: $row['department_id'] === null ? null : (int) $row['department_id'],
            departmentName: $row['department_name'],
            roles: $roles,
            permissions: $permissions,
            mustChangePassword: (bool) $row['must_change_password'],
            rawToken: $token,
            remainingSeconds: max(0, min($idleLimit - $idleFor, (int) $row['until_absolute'])),
        );
    }

    public static function logout(?string $cookie): void
    {
        $ctx = self::authenticate($cookie, false);
        if ($ctx !== null) {
            Database::execute(
                "UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(3), revoked_reason = 'logout' WHERE id = :id AND revoked_at IS NULL",
                ['id' => $ctx->sessionId]
            );
            Audit::log('auth', 'logout', 'Signed out');
        }
        self::$current = null;
        self::clearCookie();
    }

    public static function revokeUserSessions(int $userId, ?int $exceptSessionId, string $reason): int
    {
        return Database::execute(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(3), revoked_reason = :r
              WHERE user_id = :u AND revoked_at IS NULL AND id <> :except',
            ['r' => $reason, 'u' => $userId, 'except' => $exceptSessionId ?? 0]
        );
    }

    /** Values the browser needs after sign-in: identity, permissions, CSRF token, timeout settings. */
    public static function clientPayload(AuthContext $ctx): array
    {
        return [
            'user' => [
                'id' => $ctx->userId,
                'username' => $ctx->username,
                'name' => $ctx->displayName,
                'initials' => $ctx->initials,
                'department' => $ctx->departmentName,
                'roles' => $ctx->roles,
                'permissions' => $ctx->permissions,
                'must_change_password' => $ctx->mustChangePassword,
            ],
            'csrf' => self::csrfToken($ctx->rawToken),
            'session' => [
                'idle_seconds' => self::idleSeconds(),
                'warning_seconds' => self::warningSeconds(),
                'remaining_seconds' => $ctx->remainingSeconds,
            ],
        ];
    }

    // ─── CSRF ────────────────────────────────────────────────────────────

    public static function csrfToken(string $rawToken): string
    {
        return Crypto::base64url(hash_hmac('sha256', $rawToken, Crypto::subkey('csrf'), true));
    }

    public static function verifyCsrf(Request $request): void
    {
        $sent = (string) $request->header('X-CSRF-Token');
        if ($sent === '' || !hash_equals(self::csrfToken(self::require()->rawToken), $sent)) {
            throw new HttpException(403, 'csrf_failed', 'Security token missing or invalid. Reload the page and try again.');
        }
    }

    // ─── Passwords ───────────────────────────────────────────────────────

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function assertPasswordPolicy(string $password, string $username, string $field = 'password'): void
    {
        $min = max(8, Settings::int('security.password_min_length', 10));
        $length = mb_strlen($password, 'UTF-8');
        $error = match (true) {
            $length < $min => "Use at least $min characters.",
            $length > 128 => 'Use at most 128 characters.',
            !preg_match('/\pL/u', $password) || !preg_match('/\d/', $password) => 'Include at least one letter and one number.',
            strlen($username) >= 3 && stripos($password, $username) !== false => 'The password must not contain the username.',
            in_array(mb_strtolower($password, 'UTF-8'), self::COMMON_PASSWORDS, true) => 'This password is too common. Choose another.',
            default => null,
        };
        if ($error !== null) {
            throw HttpException::validation([$field => $error]);
        }
    }

    // ─── Internals ───────────────────────────────────────────────────────

    public static function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        if (basename($dir) === 'api') {
            $dir = str_replace('\\', '/', dirname($dir));
        }
        return rtrim($dir, '/') . '/';
    }

    private static function recordAttempt(string $username, ?int $userId, Request $request, bool $success, string $reason): void
    {
        Database::execute(
            'INSERT INTO login_attempts (username, user_id, ip_address, success, reason, attempted_at)
             VALUES (:u, :uid, :ip, :s, :r, UTC_TIMESTAMP(3))',
            ['u' => mb_substr($username, 0, 50), 'uid' => $userId, 'ip' => $request->ip, 's' => $success ? 1 : 0, 'r' => $reason]
        );
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
    }

    private static function cookieOptions(int $expires): array
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off');
        return [
            'expires' => $expires,
            'path' => self::basePath(),
            'domain' => '',
            'secure' => $https || (bool) Config::get('session.secure_cookie', false),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    private static function setCookie(string $value): void
    {
        setcookie(self::cookieName(), $value, self::cookieOptions(0));
    }

    private static function clearCookie(): void
    {
        setcookie(self::cookieName(), '', self::cookieOptions(time() - 3600));
    }
}
