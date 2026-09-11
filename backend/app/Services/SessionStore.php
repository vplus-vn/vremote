<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Contracts\SessionRepository;
use Illuminate\Support\Facades\File;

final class SessionStore implements SessionRepository
{
    private string $path;
    public function __construct() { $this->path = storage_path('app/vremote-sessions.json'); }
    public function put(array $session): void { $all = $this->all(); $all[$session['sessionId']] = $session; $this->write($all); }
    public function get(string $id): ?array { $v = $this->all()[$id] ?? null; return is_array($v) ? $v : null; }
    public function forget(string $id): void { $all=$this->all(); unset($all[$id]); $this->write($all); }
    public function all(): array { if (!File::exists($this->path)) return []; $v=json_decode((string)File::get($this->path), true); return is_array($v)?$v:[]; }
    private function write(array $value): void { File::ensureDirectoryExists(dirname($this->path)); File::put($this->path, json_encode($value, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), true); }
}
