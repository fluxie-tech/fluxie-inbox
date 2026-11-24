<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\ChannelCard;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ChannelCardCallsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'k']);
    }

    private function connect(): Channel
    {
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(ChannelCard::class, ['channel' => $channel])
            ->call('connect')
            ->assertSet('errorMessage', null);

        return $channel->fresh();
    }

    public function test_conectar_aplica_o_app_de_ligacoes(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response([], 404),
            '*/api/sessions*' => Http::response(['status' => 'STARTING'], 201),
            'GET */api/apps*' => Http::response([], 200),
            '*/api/apps*' => Http::response([], 201),
        ]);

        $channel = $this->connect();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/apps'));
        $this->assertSame(ChannelStatus::STARTING, $channel->status);
    }

    public function test_falha_no_app_de_ligacoes_nao_impede_conectar(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response([], 404),
            '*/api/sessions*' => Http::response(['status' => 'STARTING'], 201),
            '*/api/apps*' => Http::response(['message' => 'erro'], 500),
        ]);

        $this->assertSame(ChannelStatus::STARTING, $this->connect()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/apps'));
    }
}
