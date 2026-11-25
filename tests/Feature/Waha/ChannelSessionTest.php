<?php

namespace Tests\Feature\Waha;

use App\Models\Channel;
use App\Services\Waha\ChannelSession;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
    }

    private function cs(): ChannelSession
    {
        return new ChannelSession(Channel::query()->firstWhere('waha_session', 'main') ?? Channel::factory()->create(['waha_session' => 'main']));
    }

    public function test_start_cria_a_sessao_quando_ela_nao_existe(): void
    {
        Http::fake([
            'http://waha.test:3000/api/sessions/main' => Http::response([], 404),
            'http://waha.test:3000/api/sessions' => Http::response([], 201),
        ]);

        $this->cs()->start();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://waha.test:3000/api/sessions' && $r['name'] === 'main');
    }

    public function test_stop_e_delete_session_batem_nos_endpoints_da_sessao(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->cs()->stop();
        $this->cs()->deleteSession();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'http://waha.test:3000/api/sessions/main/stop');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://waha.test:3000/api/sessions/main');
    }

    public function test_session_e_pairing_code_passam_pelo_waha(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response(['status' => 'WORKING']),
            '*/auth/request-code' => Http::response(['code' => 'ABCD-1234']),
        ]);

        $this->assertSame('WORKING', $this->cs()->session()['status']);
        $this->assertSame('ABCD-1234', $this->cs()->requestPairingCode('+55 11 99999-8888'));
    }

    public function test_send_test_message_envia_e_depois_fica_offline(): void
    {
        Http::fake([
            '*/api/sendText' => Http::response(['id' => 'ABC'], 201),
            '*/presence' => Http::response([]),
        ]);

        $id = $this->cs()->sendTestMessage('5511999998888', 'oi');

        $this->assertSame('ABC', $id);
        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/api/sendText') && $r['chatId'] === '5511999998888@c.us' && $r['text'] === 'oi',
            fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'offline',
        ]);
    }

    public function test_falha_na_presenca_nao_derruba_o_envio(): void
    {
        Http::fake([
            '*/api/sendText' => Http::response(['id' => 'ABC'], 201),
            '*/presence' => Http::response([], 500),
        ]);

        $this->assertSame('ABC', $this->cs()->sendTestMessage('5511999998888', 'oi'));
    }

    public function test_falha_no_envio_sobe_a_excecao(): void
    {
        Http::fake(['*/api/sendText' => Http::response([], 500)]);

        $this->expectException(WahaRequestException::class);

        $this->cs()->sendTestMessage('5511999998888', 'oi');
    }
}
