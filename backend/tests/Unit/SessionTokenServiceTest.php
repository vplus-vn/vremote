<?php
namespace Tests\Unit;
use App\Services\SessionTokenService;
use Tests\TestCase;
final class SessionTokenServiceTest extends TestCase{
 public function test_round_trip_and_tamper_detection():void{$s=app(SessionTokenService::class);$t=$s->issue('S-1','DEV-1','view',60);$this->assertSame('S-1',$s->verify($t)['sid']);$this->assertNull($s->verify($t.'x'));}
}
