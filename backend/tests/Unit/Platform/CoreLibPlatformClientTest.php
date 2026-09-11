<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Domain\Platform\PlatformContext;
use App\Infrastructure\Platform\CoreLibPlatformClient;
use Firebase\JWT\JWT;
use Platform\Core\Http\Middleware\CheckAuthMiddleware;
use Tests\TestCase;

/**
 * Verifies VRemote's core-lib auth adapter (#12): a Platform-issued RS256 JWT is
 * verified through vplus-platform/core-lib's CheckAuthMiddleware and mapped into a
 * PlatformContext, keeping AuthorizationService / controllers unchanged.
 */
final class CoreLibPlatformClientTest extends TestCase
{
    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($res, 'openssl keypair generation failed');

        openssl_pkey_export($res, $this->privateKey);
        $this->publicKey = (string) openssl_pkey_get_details($res)['key'];
    }

    private function client(?string $issuer = null, ?string $audience = null): CoreLibPlatformClient
    {
        return new CoreLibPlatformClient(new CheckAuthMiddleware($this->publicKey, $issuer, $audience));
    }

    /** @param array<string,mixed> $claims */
    private function sign(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256');
    }

    public function test_valid_token_maps_to_platform_context(): void
    {
        $token = $this->sign([
            'sub' => 'user-1',
            'tenantId' => 'T-42',
            'permissions' => ['edge.admin.view', 'edge.admin.control'],
            'roles' => ['operator'],
            'scopes' => ['device:DEV-1'],
            'exp' => time() + 300,
        ]);

        $context = $this->client()->verify($token);

        self::assertInstanceOf(PlatformContext::class, $context);
        self::assertSame('user-1', $context->userId);
        self::assertSame('T-42', $context->tenantId);
        self::assertTrue($context->hasPermission('edge.admin.control'));
        self::assertTrue($context->hasRole('operator'));
        self::assertTrue($context->hasScope('device:DEV-1'));
    }

    public function test_empty_token_returns_null(): void
    {
        self::assertNull($this->client()->verify(''));
    }

    public function test_tampered_token_returns_null(): void
    {
        $token = $this->sign(['sub' => 'user-1', 'exp' => time() + 300]);

        self::assertNull($this->client()->verify($token.'tampered'));
    }

    public function test_token_signed_by_other_key_returns_null(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPrivate);
        $foreignToken = JWT::encode(['sub' => 'attacker', 'exp' => time() + 300], $otherPrivate, 'RS256');

        self::assertNull($this->client()->verify($foreignToken));
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $token = $this->sign(['sub' => 'user-1', 'iss' => 'https://evil.example', 'exp' => time() + 300]);

        self::assertNull($this->client('https://id.vplus.vn')->verify($token));
    }
}
