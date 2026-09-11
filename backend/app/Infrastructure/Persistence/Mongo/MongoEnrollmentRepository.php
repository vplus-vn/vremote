<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mongo;

use App\Domain\Contracts\EnrollmentRepository;
use Illuminate\Support\Facades\DB;

/** MongoDB enrollment store (DSG-VREMOTE-002 §3/§7). Stores only hashed secrets. */
final class MongoEnrollmentRepository implements EnrollmentRepository
{
    private const COLLECTION = 'enrollments';

    private function table()
    {
        return DB::connection('mongodb')->table(self::COLLECTION);
    }

    public function get(string $enrollmentId): ?array
    {
        $doc = $this->table()->where('_id', $enrollmentId)->first();

        return $doc ? $this->hydrate((array) $doc) : null;
    }

    public function findByCodeHash(string $codeHash): ?array
    {
        $doc = $this->table()->where('codeHash', $codeHash)->first();

        return $doc ? $this->hydrate((array) $doc) : null;
    }

    public function create(array $enrollment): void
    {
        $enrollment['_id'] = $enrollment['enrollmentId'];
        $this->table()->insert($enrollment);
    }

    public function update(string $enrollmentId, array $fields): void
    {
        $this->table()->where('_id', $enrollmentId)->update($fields);
    }

    /** @param array<string,mixed> $doc */
    private function hydrate(array $doc): array
    {
        $doc['enrollmentId'] = (string) ($doc['enrollmentId'] ?? $doc['_id'] ?? '');
        unset($doc['_id']);

        return $doc;
    }
}
