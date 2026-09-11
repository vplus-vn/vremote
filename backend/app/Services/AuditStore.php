<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Contracts\AuditRepository;
use Illuminate\Support\Facades\File;

final class AuditStore implements AuditRepository
{
    private string $path;
    public function __construct(){ $this->path=storage_path('app/vremote-audit.json'); }
    public function append(array $entry): void {
        $items=$this->all();
        array_unshift($items,$entry+['id'=>'AUD-'.bin2hex(random_bytes(8)),'ts'=>now()->toIso8601String()]);
        $this->write(array_slice($items,0,5000));
    }
    public function all(): array {
        if(!File::exists($this->path)) return [];
        $v=json_decode((string)File::get($this->path),true); return is_array($v)?$v:[];
    }
    private function write(array $v):void{File::ensureDirectoryExists(dirname($this->path));File::put($this->path,json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),true);}

    /**
     * NOTE: the read-modify-write in {@see append()} is still not atomic across processes;
     * true durability under concurrency is delivered in v0.2.0 by a per-event MongoDB
     * insert (DSG-VREMOTE-002 §3). LOCK_EX here only prevents a torn file on write.
     */
}
