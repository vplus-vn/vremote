<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Contracts\CommandRepository;
use Illuminate\Support\Facades\File;

final class CommandStore implements CommandRepository
{
    private string $path;
    public function __construct(){ $this->path=storage_path('app/vremote-commands.json'); }
    public function get(string $id):?array{ $all=$this->all();$v=$all[$id]??null;return is_array($v)?$v:null; }
    public function put(array $command):void{$all=$this->all();$all[$command['commandId']]=$command;$this->write($all);}
    private function all():array{if(!File::exists($this->path))return []; $v=json_decode((string)File::get($this->path),true);return is_array($v)?$v:[];}
    private function write(array $v):void{File::ensureDirectoryExists(dirname($this->path));File::put($this->path,json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),true);}
}
