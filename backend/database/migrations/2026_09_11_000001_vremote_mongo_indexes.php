<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the MongoDB indexes for VRemote domain collections (DSG-VREMOTE-002 §3).
 * Defensive: only runs on the mongodb connection and swallows per-index errors so a
 * driver/API version difference never blocks a deploy. Indexes are performance/TTL
 * helpers — correctness (e.g. nonce replay) relies on the always-present `_id` unique
 * index, not on these.
 *
 * NOTE: TTL indexes only expire documents whose field is a BSON UTCDateTime. The nonce/
 * revoked-session repositories store epoch ints today, so treat the TTL indexes below as
 * ready-for-date-fields; until then the collections are safely bounded by explicit checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ((string) config('database.default') !== 'mongodb' && env('DB_CONNECTION') !== 'mongodb') {
            return;
        }

        $db = $this->database();
        if ($db === null) {
            return;
        }

        $indexes = [
            'devices' => [[['ownerTenantId' => 1], []], [['machineCode' => 1], ['sparse' => true]]],
            'sessions' => [[['deviceId' => 1], []], [['tenantId' => 1], []]],
            'commands' => [[['deviceId' => 1], []]],
            'audit_events' => [[['tenantId' => 1, 'deviceId' => 1, 'ts' => -1], []]],
            'enrollments' => [[['deviceId' => 1], []], [['expiresAt' => 1], ['expireAfterSeconds' => 0]]],
            'device_access_grants' => [[['deviceId' => 1], []]],
            'device_transfers' => [[['deviceId' => 1], []]],
            'signing_nonces' => [[['seenAt' => 1], ['expireAfterSeconds' => 600]]],
            'revoked_sessions' => [[['expiresAt' => 1], ['expireAfterSeconds' => 0]]],
        ];

        foreach ($indexes as $collection => $specs) {
            foreach ($specs as [$keys, $options]) {
                try {
                    $db->selectCollection($collection)->createIndex($keys, $options);
                } catch (\Throwable) {
                    // Ignore — index is an optimization, not a correctness requirement.
                }
            }
        }
    }

    public function down(): void
    {
        // Indexes are dropped with their collections; no destructive down migration.
    }

    private function database(): ?object
    {
        try {
            $conn = DB::connection('mongodb');
            foreach (['getMongoDB', 'getDatabase'] as $method) {
                if (method_exists($conn, $method)) {
                    return $conn->{$method}();
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }
};
