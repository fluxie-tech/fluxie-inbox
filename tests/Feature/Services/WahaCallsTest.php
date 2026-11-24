<?php

namespace Tests\Feature\Services;

use App\Models\Channel;
use App\Services\Waha\WahaCalls;
use App\Settings\CallSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WahaCallsTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT = 'Este número não recebe ligações. Envie sua mensagem em texto ou áudio que responderemos por aqui.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'k']);
    }

    /** @return array<string, mixed> */
    private function app(string $session, string $message, bool $enabled = true): array
    {
        return [
            'id' => 'app_'.$session,
            'session' => $session,
            'app' => 'calls',
            'config' => ['group' => ['reject' => true], 'dm' => ['message' => $message, 'reject' => true]],
            'enabled' => $enabled,
        ];
    }

    private function channel(string $session = 'main'): Channel
    {
        return Channel::factory()->connected()->create(['waha_session' => $session]);
    }

    private function assertNeverRestarted(): void
    {
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/api/sessions/'));
    }

    public function test_padrao_recusa_com_o_texto_padrao(): void
    {
        $this->assertTrue(CallSettings::rejects());
        $this->assertSame(self::DEFAULT, CallSettings::message());
    }

    public function test_textos_anteriores_ficam_conhecidos(): void
    {
        CallSettings::save(true, 'A');
        CallSettings::save(true, 'B');

        $this->assertEqualsCanonicalizing(['B', 'A', self::DEFAULT], CallSettings::knownMessages());

        CallSettings::save(true, 'A');
        CallSettings::save(true, 'B');
        $this->assertCount(3, CallSettings::knownMessages());

        foreach (range(1, 12) as $i) {
            CallSettings::save(true, "T{$i}");
        }

        $this->assertCount(11, CallSettings::knownMessages());
        $this->assertContains('T12', CallSettings::knownMessages());
        $this->assertContains('T2', CallSettings::knownMessages());
        $this->assertNotContains('T1', CallSettings::knownMessages());
    }

    public function test_cria_o_app_quando_nao_existe(): void
    {
        Http::fake(['*/api/apps*' => Http::sequence()->push([], 200)->push([], 201)]);
        CallSettings::save(true, 'Sem ligações');

        $this->assertTrue((new WahaCalls)->apply($this->channel()));

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'http://waha.test:3000/api/apps?session=main');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'http://waha.test:3000/api/apps'
            && $r['app'] === 'calls' && $r['id'] === 'app_main' && $r['session'] === 'main'
            && $r->data()['config'] === ['dm' => ['reject' => true, 'message' => 'Sem ligações'], 'group' => ['reject' => true]]);
        $this->assertNeverRestarted();
    }

    public function test_atualiza_o_app_que_ja_existe(): void
    {
        Http::fake(['*/api/apps*' => Http::sequence()->push([$this->app('main', 'antigo')], 200)->push([], 200)]);
        CallSettings::save(true, 'Novo texto');

        $this->assertTrue((new WahaCalls)->apply($this->channel()));

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'http://waha.test:3000/api/apps/app_main'
            && data_get($r->data(), 'config.dm.message') === 'Novo texto');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertNeverRestarted();
    }

    public function test_app_igual_nao_escreve(): void
    {
        Http::fake(['*/api/apps*' => Http::response([$this->app('main', 'Igual')], 200)]);
        CallSettings::save(true, 'Igual');

        $this->assertFalse((new WahaCalls)->apply($this->channel()));

        Http::assertSentCount(1);
        $this->assertNeverRestarted();
    }

    public function test_chave_desligada_remove_o_app(): void
    {
        Http::fake(['*/api/apps*' => Http::response([$this->app('main', 'x')], 200)]);
        CallSettings::save(false, 'x');

        $this->assertTrue((new WahaCalls)->apply($this->channel()));

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://waha.test:3000/api/apps/app_main');
        $this->assertNeverRestarted();
    }

    public function test_chave_desligada_sem_app_so_consulta(): void
    {
        Http::fake(['*/api/apps*' => Http::response([], 200)]);
        CallSettings::save(false, 'x');

        $this->assertFalse((new WahaCalls)->apply($this->channel()));

        Http::assertSentCount(1);
        $this->assertNeverRestarted();
    }

    public function test_apply_all_so_canais_ativos_e_conectados(): void
    {
        $this->channel('um');
        $this->channel('dois');
        Channel::factory()->create(['waha_session' => 'parado']);
        Channel::factory()->connected()->create(['waha_session' => 'inativo', 'active' => false]);
        CallSettings::save(true, 'Igual');
        Http::fake([
            '*/api/apps?session=um' => Http::response([], 200),
            '*/api/apps?session=dois' => Http::response([$this->app('dois', 'Igual')], 200),
            '*/api/apps' => Http::response([], 201),
        ]);

        $this->assertSame(['applied' => 1, 'unchanged' => 1, 'failed' => 0], (new WahaCalls)->applyAll());

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'parado') || str_contains($r->url(), 'inativo'));
    }

    public function test_apply_all_conta_falha_e_continua(): void
    {
        $this->channel('um');
        $this->channel('dois');
        Log::shouldReceive('warning')->once();
        Http::fake([
            '*/api/apps?session=um' => Http::response('erro', 500),
            '*/api/apps?session=dois' => Http::response([], 200),
            '*/api/apps' => Http::response([], 201),
        ]);

        $this->assertSame(['applied' => 1, 'unchanged' => 0, 'failed' => 1], (new WahaCalls)->applyAll());
    }

    public function test_apply_quietly_nao_lanca(): void
    {
        Log::shouldReceive('warning')->once();
        Http::fake(['*' => Http::response('erro', 500)]);

        (new WahaCalls)->applyQuietly($this->channel());

    }
}
