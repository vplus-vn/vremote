<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\VRemoteSigningService;
use PHPUnit\Framework\TestCase;

final class VRemoteSigningServiceTest extends TestCase
{
    private VRemoteSigningService $signer;

    protected function setUp(): void
    {
        $this->signer = new VRemoteSigningService();
    }

    public function test_session_enable_golden_vector(): void
    {
        $canonical = $this->signer->canonicalSessionEnable([
            'deviceId' => 'DEV-001', 'sessionId' => 'S-1001', 'capability' => 'view',
            'ttlSec' => 1800, 'nonce' => 'nonce-abc', 'ts' => 1760000000,
        ]);

        self::assertSame("DEV-001\nS-1001\nview\n1800\nnonce-abc\n1760000000", $canonical);
        self::assertSame(
            '+z47Fx1AXfmSNSFdKlyK6xPRnPivKGLFMrKuDIIEtlY=',
            $this->signer->sign('dev-secret-123', $canonical),
        );
    }

    public function test_command_golden_vector_a(): void
    {
        $args = '{"target":"vbridge"}';
        self::assertSame('c5f85fa5aba702326ec0da5d3cd57b8e98bb2e6634867d7c13451f5c863c668e', $this->signer->argsHash($args));
        $canonical = $this->signer->canonicalCommand([
            'deviceId' => 'DEV-001', 'commandId' => 'CMD-0001', 'type' => 'restart-app',
            'argsHash' => $this->signer->argsHash($args), 'nonce' => 'nonce-xyz', 'ts' => 1760000000,
        ]);
        self::assertSame('/lAXmjDUVdnVSZ4RM+T323imtc0UHthJ8m4159gYX5A=', $this->signer->sign('dev-secret-123', $canonical));
    }

    public function test_command_golden_vector_b_and_empty_args(): void
    {
        self::assertSame('', $this->signer->argsHash(null));
        $canonical = $this->signer->canonicalCommand([
            'deviceId' => 'DEV-001', 'commandId' => 'CMD-0002', 'type' => 'health',
            'argsHash' => '', 'nonce' => 'nonce-000', 'ts' => 1760000000,
        ]);
        self::assertSame('n5obzyY/A5PsQ1mhLHXqUJsmAK0OB9m/vvB+kBUisUY=', $this->signer->sign('dev-secret-123', $canonical));
    }

    public function test_verification_is_constant_time_and_rejects_tampering(): void
    {
        $canonical = $this->signer->canonicalSessionEnable([
            'deviceId' => 'DEV-001', 'sessionId' => 'S-1001', 'capability' => 'view',
            'ttlSec' => 1800, 'nonce' => 'nonce-abc', 'ts' => 1760000000,
        ]);
        $sig = $this->signer->sign('dev-secret-123', $canonical);
        self::assertTrue($this->signer->verify('dev-secret-123', $canonical, $sig));
        self::assertFalse($this->signer->verify('wrong-secret', $canonical, $sig));
        self::assertFalse($this->signer->verify('dev-secret-123', $canonical . 'x', $sig));
    }
}
