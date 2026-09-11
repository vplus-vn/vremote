<?php

declare(strict_types=1);

namespace App\Domain\Platform;

/**
 * Identity context received from the VPlus Platform (DSG-VREMOTE-002 §4).
 *
 * Framework-free, immutable. VRemote does not own users/tenants/roles — the
 * Platform is authoritative and hands VRemote the minimum context needed to
 * make an authorization decision for a device/session/command.
 */
final class PlatformContext
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $tenantId,
        public readonly array $roles = [],
        public readonly array $permissions = [],
        public readonly array $scopes = [],
    ) {
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** @param array<string,mixed> $claims */
    public static function fromClaims(array $claims): self
    {
        return new self(
            userId: (string) ($claims['userId'] ?? $claims['sub'] ?? ''),
            tenantId: isset($claims['tenantId']) ? (string) $claims['tenantId'] : null,
            roles: self::stringList($claims['roles'] ?? []),
            permissions: self::stringList($claims['permissions'] ?? []),
            scopes: self::stringList($claims['scopes'] ?? []),
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($v): string => (string) $v, $value), static fn (string $v): bool => $v !== ''));
    }
}
