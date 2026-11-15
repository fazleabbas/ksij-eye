<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Maps API routes to controller actions. Every route declares its access rule and the
 * router enforces it before any controller code runs — there is no unprotected default.
 */
final class Router
{
    /** No session required (sign-in, sign-out). */
    public const PUBLIC = '@public';
    /** Any signed-in user, including one who still has to change a temporary password. */
    public const SESSION = '@session';
    /** Any signed-in user with a usable account. */
    public const AUTH = '@auth';

    private array $routes = [];

    /** @param string $access a permission code, or one of the constants above */
    public function add(string $method, string $pattern, array $handler, string $access, bool $touch = true): void
    {
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d{1,18})', $pattern) . '$#',
            'handler' => $handler,
            'access' => $access,
            'touch' => $touch,
        ];
    }

    public function dispatch(Request $request): never
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = array_map('intval', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            $this->authorize($request, $route);
            [$class, $action] = $route['handler'];
            Response::ok((new $class())->$action($request, $params));
        }
        throw $pathMatched
            ? new HttpException(405, 'method_not_allowed', 'Method not allowed.')
            : new HttpException(404, 'not_found', 'Endpoint not found.');
    }

    private function authorize(Request $request, array $route): void
    {
        if ($request->method !== 'GET') {
            $request->assertSameOrigin();
        }
        if ($route['access'] === self::PUBLIC) {
            return;
        }

        $ctx = Auth::authenticate($request->cookie(Auth::cookieName()), $route['touch']);
        if ($ctx === null) {
            throw Auth::sessionExpired()
                ? new HttpException(401, 'session_expired', 'Your session has expired. Please sign in again.')
                : new HttpException(401, 'unauthenticated', 'Please sign in.');
        }
        if ($request->method !== 'GET') {
            Auth::verifyCsrf($request);
        }
        if ($ctx->mustChangePassword && $route['access'] !== self::SESSION) {
            throw new HttpException(403, 'password_change_required', 'You must change your password before continuing.');
        }
        if (!in_array($route['access'], [self::SESSION, self::AUTH], true) && !$ctx->can($route['access'])) {
            Audit::log('security', 'access.denied', "Denied {$request->method} {$request->path}", null, null, ['permission' => $route['access']]);
            throw new HttpException(403, 'forbidden', 'You do not have permission to perform this action.');
        }
    }
}
