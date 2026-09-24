<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * [v0.1.1] Chuẩn hoá API v1 — envelope {data}/{error}, tương thích ngược (legacy mặc định),
 * lỗi framework theo envelope chuẩn, ops endpoint health/ready, X-Correlation-Id (2026-09-23)
 */
final class ApiStandardTest extends TestCase
{
    private const V1 = ['X-Api-Envelope' => 'v1'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'vremote.dev_auth' => true,
            'vremote.devices' => json_encode(['DEV-1' => ['machineCode' => 'MC-1', 'status' => 'online', 'ownerTenantId' => 'T-LOCAL']]),
            'vremote.api_envelope_default' => 'legacy',
        ]);

        // Route thử nằm dưới /api/vremote/v1 để đi qua nhóm middleware `api` thật.
        Route::middleware('api')->prefix('api/vremote/v1/__test')->group(function (): void {
            Route::get('/replay', static fn () => response()->json(['commandId' => 'C1', 'accepted' => false, 'result' => 'replay_detected'], 409));
            Route::get('/boom', static fn () => throw new \RuntimeException('secret internal detail'));
            Route::get('/enveloped', static fn () => response()->json(['data' => ['accessToken' => 't']]));
            Route::get('/abort', static fn () => abort(400, 'bad status filter'));
        });
    }

    public function test_health_is_unversioned_unwrapped_and_carries_correlation_id(): void
    {
        $res = $this->getJson('/api/vremote/health', self::V1 + ['X-Correlation-Id' => 'cid-12345678']);

        $res->assertOk()->assertExactJson(['status' => 'ok', 'service' => 'vremote-backend']);
        $res->assertHeader('X-Correlation-Id', 'cid-12345678');
        $res->assertHeaderMissing('X-Api-Envelope');
    }

    public function test_correlation_id_is_generated_when_missing(): void
    {
        $res = $this->getJson('/api/vremote/v1/devices');

        $this->assertNotEmpty($res->headers->get('X-Correlation-Id'));
    }

    public function test_ready_reports_checks(): void
    {
        config(['vremote.signaling_internal_url' => 'http://signaling.internal:8788']);
        Http::fake(['signaling.internal:8788/*' => Http::response('', 404)]);

        $this->getJson('/api/vremote/ready')->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.mongodb', 'skipped')
            ->assertJsonPath('checks.signaling', 'ok');
    }

    public function test_ready_is_503_when_a_dependency_is_down(): void
    {
        config(['vremote.signaling_internal_url' => 'http://signaling.internal:8788']);
        Http::fake(static fn () => throw new ConnectionException('down'));

        $this->getJson('/api/vremote/ready')->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.signaling', 'fail');
    }

    public function test_legacy_shape_is_default_for_existing_clients(): void
    {
        $res = $this->getJson('/api/vremote/v1/devices');

        $res->assertOk()->assertJsonPath('items.0.deviceId', 'DEV-1')->assertJsonPath('nextCursor', null);
        $res->assertJsonMissingPath('data');
        $res->assertHeader('X-Api-Envelope', 'legacy');
    }

    public function test_v1_envelope_wraps_success_when_requested(): void
    {
        $res = $this->getJson('/api/vremote/v1/devices', self::V1);

        $res->assertOk()->assertJsonPath('data.items.0.deviceId', 'DEV-1')->assertJsonPath('data.nextCursor', null);
        $this->assertSame(['data'], array_keys($res->json()));
        $res->assertHeader('X-Api-Envelope', 'v1');

        $this->getJson('/api/vremote/v1/devices/DEV-1', self::V1)->assertOk()
            ->assertJsonPath('data.deviceId', 'DEV-1')
            ->assertJsonPath('data.machineCode', 'MC-1');
    }

    public function test_default_can_be_flipped_by_config_and_legacy_still_selectable(): void
    {
        config(['vremote.api_envelope_default' => 'v1']);

        $this->getJson('/api/vremote/v1/devices')->assertJsonPath('data.items.0.deviceId', 'DEV-1');
        $this->getJson('/api/vremote/v1/devices', ['X-Api-Envelope' => 'legacy'])
            ->assertJsonPath('items.0.deviceId', 'DEV-1')
            ->assertJsonMissingPath('data');
    }

    public function test_controller_errors_keep_standard_envelope_in_both_modes(): void
    {
        foreach ([[], self::V1] as $headers) {
            $this->getJson('/api/vremote/v1/devices/NOPE', $headers)->assertNotFound()
                ->assertExactJson(['error' => ['code' => 'NOT_FOUND', 'message' => 'Device not found']]);
        }
    }

    public function test_validation_error_uses_standard_envelope(): void
    {
        foreach ([[], self::V1] as $headers) {
            $res = $this->postJson('/api/vremote/v1/devices', [], $headers);

            $res->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['machineCode']]]);
            $res->assertJsonMissingPath('errors');
        }
    }

    public function test_unknown_route_and_wrong_method_use_standard_envelope(): void
    {
        $this->getJson('/api/vremote/v1/does-not-exist')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->putJson('/api/vremote/v1/devices')->assertStatus(405)->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        $this->getJson('/api/vremote/v1/__test/abort')->assertStatus(400)
            ->assertExactJson(['error' => ['code' => 'BAD_REQUEST', 'message' => 'bad status filter']]);
    }

    public function test_unhandled_exception_is_500_without_leaking_detail(): void
    {
        $res = $this->getJson('/api/vremote/v1/__test/boom');

        $res->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('secret internal detail', (string) $res->getContent());
    }

    public function test_unauthenticated_uses_standard_envelope(): void
    {
        config(['vremote.dev_auth' => false, 'vremote.platform_url' => '', 'vremote.jwt_public_key' => '']);

        $this->getJson('/api/vremote/v1/devices', self::V1)->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_legacy_error_body_is_converted_only_in_v1_mode(): void
    {
        $this->getJson('/api/vremote/v1/__test/replay')->assertStatus(409)
            ->assertExactJson(['commandId' => 'C1', 'accepted' => false, 'result' => 'replay_detected']);

        $this->getJson('/api/vremote/v1/__test/replay', self::V1)->assertStatus(409)
            ->assertJsonPath('error.code', 'REPLAY_DETECTED')
            ->assertJsonPath('error.details.commandId', 'C1');
    }

    public function test_session_close_404_is_an_error_envelope(): void
    {
        $this->deleteJson('/api/vremote/v1/sessions/S-NOPE')->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_upstream_envelope_is_not_double_wrapped(): void
    {
        $this->getJson('/api/vremote/v1/__test/enveloped', self::V1)->assertOk()
            ->assertExactJson(['data' => ['accessToken' => 't']]);
    }
}
