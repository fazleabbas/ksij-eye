<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Validator;

final class AuthController
{
    public function login(Request $request): array
    {
        $v = Validator::make($request->all())
            ->string('username', true, 50)
            ->string('password', true, 128, trim: false)
            ->validate();
        return Auth::login($v['username'], $v['password'], $request);
    }

    public function logout(Request $request): array
    {
        Auth::logout($request->cookie(Auth::cookieName()));
        return ['signed_out' => true];
    }

    public function me(): array
    {
        return Auth::clientPayload(Auth::require());
    }

    /** Remaining session time without extending it (route is registered with touch: false). */
    public function session(): array
    {
        $ctx = Auth::require();
        return [
            'remaining_seconds' => $ctx->remainingSeconds,
            'idle_seconds' => Auth::idleSeconds(),
            'warning_seconds' => Auth::warningSeconds(),
        ];
    }

    /** "Stay signed in": the router has already refreshed the session's activity time. */
    public function keepalive(): array
    {
        return $this->session();
    }

    public function changePassword(Request $request): array
    {
        $ctx = Auth::require();
        $v = Validator::make($request->all())
            ->string('current_password', true, 128, trim: false)
            ->string('new_password', true, 128, trim: false)
            ->validate();

        $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = :id', ['id' => $ctx->userId]);
        if (!password_verify($v['current_password'], $hash)) {
            throw HttpException::validation(['current_password' => 'Current password is incorrect.']);
        }
        if (password_verify($v['new_password'], $hash)) {
            throw HttpException::validation(['new_password' => 'Choose a password different from the current one.']);
        }
        Auth::assertPasswordPolicy($v['new_password'], $ctx->username, 'new_password');

        Database::execute(
            'UPDATE users SET password_hash = :h, must_change_password = 0, password_changed_at = UTC_TIMESTAMP(3),
                    updated_at = UTC_TIMESTAMP(3), updated_by = :by, row_version = row_version + 1
              WHERE id = :id',
            ['h' => Auth::hashPassword($v['new_password']), 'by' => $ctx->userId, 'id' => $ctx->userId]
        );
        $revoked = Auth::revokeUserSessions($ctx->userId, $ctx->sessionId, 'password_changed');
        Audit::log('auth', 'password.changed', 'Changed own password' . ($revoked ? " ($revoked other session(s) signed out)" : ''), 'user', $ctx->userId);

        return ['changed' => true];
    }
}
