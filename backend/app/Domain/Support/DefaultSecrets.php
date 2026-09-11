<?php

declare(strict_types=1);

namespace App\Domain\Support;

final class DefaultSecrets implements Secrets
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous 0/O/1/I

    public function enrollmentCode(): string
    {
        $g = static function (): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            return $s;
        };

        return $g() . '-' . $g();
    }

    public function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    public function verify(string $value, string $hash): bool
    {
        return hash_equals($hash, $this->hash($value));
    }
}
