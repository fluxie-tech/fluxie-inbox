<?php

namespace Tests\Feature\Reset;

use App\Services\Reset\SessionPurger;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SessionPurgerTest extends TestCase
{
    public function test_apaga_so_chaves_de_sessao_em_todas_as_paginas_do_scan(): void
    {
        config(['database.redis.options.prefix' => 'dbp-']);
        [$a, $b] = [Str::random(40), Str::random(40)];
        $redis = Mockery::mock(PredisConnection::class);
        $redis->shouldReceive('scan')->once()->with(0, Mockery::any())->andReturn(['17', ['dbp-cp-'.$a, 'dbp-cp-restore-database', 'dbp-queues:history', 'dbp-cp-'.Str::random(39)]]);
        $redis->shouldReceive('scan')->once()->with('17', Mockery::any())->andReturn([0, ['cp-'.$b, 'dbp-cp-'.Str::random(41)]]);
        $redis->shouldReceive('del')->once()->with('cp-'.$a);
        $redis->shouldReceive('del')->once()->with('cp-'.$b);

        $this->assertSame(2, (new SessionPurger)->purgeRedis($redis, 'cp-'));
    }

    public function test_scan_vazio_nao_apaga_nada(): void
    {
        $redis = Mockery::mock(PredisConnection::class);
        $redis->shouldReceive('scan')->once()->andReturn(false);
        $redis->shouldNotReceive('del');

        $this->assertSame(0, (new SessionPurger)->purgeRedis($redis, 'cp-'));
    }

    public function test_driver_de_sessao_sem_redis_nao_apaga_nada(): void
    {
        $this->assertSame(0, (new SessionPurger)->purge());
    }

    public function test_com_sessao_no_redis_some_a_sessao_e_preserva_o_lock(): void
    {
        if (config('session.driver') !== 'redis') {
            $this->markTestSkipped('Precisa de SESSION_DRIVER=redis (suíte com Redis).');
        }

        $handler = app('session')->driver()->getHandler();
        $id = Str::random(40);
        $handler->write($id, 'dados');
        $lock = Cache::lock('restore-database', 60);
        $this->assertTrue($lock->get());

        try {
            $this->assertGreaterThanOrEqual(1, (new SessionPurger)->purge());
            $this->assertSame('', $handler->read($id));
            $this->assertFalse(Cache::lock('restore-database', 60)->get(), 'o lock sobreviveu');
        } finally {
            $lock->release();
        }
    }
}
