<?php
declare(strict_types=1);

namespace App\Core;

/** The authenticated user for the current request. Permissions are reloaded on every request. */
final class AuthContext
{
    public function __construct(
        public readonly int $userId,
        public readonly int $sessionId,
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $initials,
        public readonly ?int $departmentId,
        public readonly ?string $departmentName,
        /** @var list<array{id:int,code:string,name:string,color:string}> */
        public readonly array $roles,
        /** @var list<string> */
        public readonly array $permissions,
        public readonly bool $mustChangePassword,
        public readonly string $rawToken,
        public readonly int $remainingSeconds,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $code): bool
    {
        foreach ($this->roles as $role) {
            if ($role['code'] === $code) {
                return true;
            }
        }
        return false;
    }
}
