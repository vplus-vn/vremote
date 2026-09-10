<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class VRemoteSigningService
{
    /** @param array<string,string|int> $fields */
    public function canonicalSessionEnable(array $fields): string
    {
        return $this->canonical([
            (string) ($fields['deviceId'] ?? ''),
            (string) ($fields['sessionId'] ?? ''),
            (string) ($fields['capability'] ?? ''),
            (string) ($fields['ttlSec'] ?? ''),
            (string) ($fields['nonce'] ?? ''),
            (string) ($fields['ts'] ?? ''),
        ]);
    }

    /** @param array<string,string> $fields */
    public function canonicalCommand(array $fields): string
    {
        return $this->canonical([
            (string) ($fields['deviceId'] ?? ''),
            (string) ($fields['commandId'] ?? ''),
            (string) ($fields['type'] ?? ''),
            (string) ($fields['argsHash'] ?? ''),
            (string) ($fields['nonce'] ?? ''),
            (string) ($fields['ts'] ?? ''),
        ]);
    }

    public function argsHash(?string $argsJson): string
    {
        return $argsJson === null ? '' : hash('sha256', $argsJson);
    }

    public function sign(string $deviceToken, string $canonical): string
    {
        if ($deviceToken === '') {
            throw new InvalidArgumentException('deviceToken must not be empty');
        }

        return base64_encode(hash_hmac('sha256', $canonical, $deviceToken, true));
    }

    public function verify(string $deviceToken, string $canonical, string $signature): bool
    {
        return hash_equals($this->sign($deviceToken, $canonical), $signature);
    }

    /** @param list<string> $parts */
    private function canonical(array $parts): string
    {
        return implode("\n", $parts);
    }
}
