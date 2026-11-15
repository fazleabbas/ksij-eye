<?php
/**
 * API front controller — the only PHP endpoint for data. Every route is authenticated and
 * permission-checked by App\Core\Router before a controller runs.
 */
declare(strict_types=1);

define('KSI_APP', true);

use App\Core\Audit;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $router = new Router();
    (require dirname(__DIR__) . '/app/routes.php')($router);
    $router->dispatch(Request::fromGlobals());
} catch (HttpException $e) {
    Response::error($e->status, $e->errorCode, $e->getMessage(), $e->fields);
} catch (\Throwable $e) {
    $reference = class_exists(Audit::class, false) ? Audit::requestId() : bin2hex(random_bytes(8));
    error_log(sprintf('[api %s] %s: %s in %s:%d', $reference, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    Response::error(500, 'server_error', "Something went wrong. Please try again or quote reference $reference to the administrator.");
}
