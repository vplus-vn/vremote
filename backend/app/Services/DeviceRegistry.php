<?php

declare(strict_types=1);

namespace App\Services;

final class DeviceRegistry
{
    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        $raw = (string) config('vremote.devices', '{}');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function get(string $deviceId): ?array
    {
        $d = $this->all()[$deviceId] ?? null;
        return is_array($d) ? $d + ['deviceId' => $deviceId] : null;
    }
}
