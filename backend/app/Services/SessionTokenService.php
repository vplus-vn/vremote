<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SessionTokenService
{
    public function issue(string $sessionId, string $deviceId, string $capability, int $ttl): string
    {
        $secret = (string) config('vremote.session_secret');
        if ($secret === '') throw new RuntimeException('VREMOTE_SESSION_SECRET is not configured');
        $payload = ['sid'=>$sessionId,'did'=>$deviceId,'cap'=>$capability,'exp'=>time()+$ttl,'jti'=>bin2hex(random_bytes(12))];
        $body = $this->b64(json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return $body.'.'.$this->b64(hash_hmac('sha256', $body, $secret, true));
    }

    public function verify(string $token): ?array
    {
        $secret = (string) config('vremote.session_secret');
        $parts = explode('.', $token, 2);
        if ($secret === '' || count($parts) !== 2) return null;
        if (!hash_equals($this->b64(hash_hmac('sha256', $parts[0], $secret, true)), $parts[1])) return null;
        $payload = json_decode($this->unb64($parts[0]), true);
        if (!is_array($payload) || !isset($payload['sid'],$payload['did'],$payload['cap'],$payload['exp']) || (int)$payload['exp'] < time()) return null;
        return $payload;
    }

    private function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function unb64(string $value): string { return base64_decode(strtr($value, '-_', '+/').'='.str_repeat('=', (4-strlen($value)%4)%4), true) ?: ''; }
}
