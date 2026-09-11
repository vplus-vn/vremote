<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contracts\AccessGrantRepository;
use App\Domain\Contracts\AuditRepository;
use App\Domain\Contracts\CommandRepository;
use App\Domain\Contracts\DeviceRepository;
use App\Domain\Contracts\DeviceTransferRepository;
use App\Domain\Contracts\EnrollmentRepository;
use App\Domain\Contracts\NonceRepository;
use App\Domain\Contracts\PlatformClient;
use App\Domain\Contracts\RevokedSessionRepository;
use App\Domain\Contracts\SessionRepository;
use App\Domain\Support\Clock;
use App\Domain\Support\DefaultSecrets;
use App\Domain\Support\Secrets;
use App\Domain\Support\SystemClock;
use App\Infrastructure\Persistence\Config\ConfigDeviceRepository;
use App\Infrastructure\Persistence\Mongo\MongoAccessGrantRepository;
use App\Infrastructure\Persistence\Mongo\MongoDeviceRepository;
use App\Infrastructure\Persistence\Mongo\MongoDeviceTransferRepository;
use App\Infrastructure\Persistence\Mongo\MongoEnrollmentRepository;
use App\Infrastructure\Persistence\Mongo\MongoNonceRepository;
use App\Infrastructure\Persistence\Mongo\MongoRevokedSessionRepository;
use App\Infrastructure\Platform\FakePlatformClient;
use App\Infrastructure\Platform\HttpPlatformClient;
use App\Infrastructure\Platform\JwtPlatformClient;
use App\Infrastructure\Platform\OidcPlatformClient;
use App\Services\AuditStore;
use App\Services\CommandStore;
use App\Services\SessionStore;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Support services.
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(Secrets::class, DefaultSecrets::class);

        // Existing file-backed stores keep the baseline session/command/audit paths.
        $this->app->bind(SessionRepository::class, SessionStore::class);
        $this->app->bind(CommandRepository::class, CommandStore::class);
        $this->app->bind(AuditRepository::class, AuditStore::class);

        // Device registry: MongoDB in production, config-JSON fallback otherwise.
        $useMongo = config('database.default') === 'mongodb'
            || env('DB_CONNECTION') === 'mongodb';
        $this->app->bind(
            DeviceRepository::class,
            $useMongo ? MongoDeviceRepository::class : ConfigDeviceRepository::class,
        );

        // v0.1.2 domain collections live in MongoDB (used by the device-lifecycle
        // and authorization endpoints). Bindings are lazy — no DB access at boot.
        $this->app->bind(EnrollmentRepository::class, MongoEnrollmentRepository::class);
        $this->app->bind(AccessGrantRepository::class, MongoAccessGrantRepository::class);
        $this->app->bind(DeviceTransferRepository::class, MongoDeviceTransferRepository::class);
        $this->app->bind(NonceRepository::class, MongoNonceRepository::class);
        $this->app->bind(RevokedSessionRepository::class, MongoRevokedSessionRepository::class);

        // Single Platform integration point (no VREMOTE_PLATFORM_MODE). Selection is
        // config-driven, not a mode toggle:
        //   - local/testing dev-auth        -> Fake (real adapter, fixed context)
        //   - VREMOTE_PLATFORM_URL set       -> OIDC: verify RS256 JWT via the platform
        //                                       JWKS (.well-known/openid-configuration) —
        //                                       the confirmed sab-com-vn/platform-service contract
        //   - a static Platform public key   -> verify the Platform-issued JWT with that key
        //   - otherwise                      -> HTTP introspection against the platform
        $this->app->bind(PlatformClient::class, function (): PlatformClient {
            $devAuth = (bool) config('vremote.dev_auth', false);
            if ($devAuth && $this->app->environment(['local', 'testing'])) {
                return new FakePlatformClient(actor: (string) config('vremote.dev_actor', 'local-admin'));
            }

            $platformUrl = (string) config('vremote.platform_url', '');
            if ($platformUrl !== '') {
                return new OidcPlatformClient($platformUrl, (string) config('vremote.jwt_alg', 'RS256'));
            }

            $publicKey = (string) config('vremote.jwt_public_key', '');
            if ($publicKey !== '') {
                return new JwtPlatformClient($publicKey, (string) config('vremote.jwt_alg', 'RS256'));
            }

            return new HttpPlatformClient($platformUrl);
        });
    }

    public function boot(): void
    {
    }
}
