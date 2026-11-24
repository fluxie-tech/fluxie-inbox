<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\Channels;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use App\Settings\CallSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelsCallsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'k']);
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
    }

    public function test_mostra_a_chave_ligada_e_o_texto_padrao(): void
    {
        Livewire::test(Channels::class)
            ->assertSet('rejectCalls', true)
            ->assertSet('callMessage', CallSettings::DEFAULT_MESSAGE)
            ->assertSee('Recusar ligações');
    }

    public function test_salvar_grava_aplica_e_audita(): void
    {
        Channel::factory()->connected()->create(['waha_session' => 'main']);
        Http::fake(['*/api/apps*' => fn (Request $r) => Http::response($r->method() === 'GET' ? [] : ['id' => 'app_1'])]);

        Livewire::test(Channels::class)
            ->set('callMessage', 'Não atendemos ligações.')
            ->call('saveCalls')
            ->assertSet('callsNotice', 'Configuração aplicada aos canais.');

        $this->assertSame('Não atendemos ligações.', CallSettings::message());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/apps'));
        $this->assertSame(1, AuditLog::query()->where('action', 'calls.settings_updated')->count());
    }

    public function test_waha_fora_do_ar_salva_e_avisa(): void
    {
        Channel::factory()->connected()->create();
        Http::fake(['*' => Http::response([], 500)]);

        Livewire::test(Channels::class)
            ->set('callMessage', 'Outro texto.')
            ->call('saveCalls')
            ->assertSet('callsNotice', 'Configuração salva; será aplicada aos canais quando o WhatsApp responder.');

        $this->assertSame('Outro texto.', CallSettings::message());
    }

    /** @return array<string, array{string}> */
    public static function invalidMessages(): array
    {
        return ['vazio' => [''], 'só espaços' => ['   '], 'controle' => ["oi\x07"], '501 caracteres' => [str_repeat('a', 501)]];
    }

    #[DataProvider('invalidMessages')]
    public function test_texto_invalido_com_chave_ligada_e_recusado(string $text): void
    {
        Livewire::test(Channels::class)
            ->set('callMessage', $text)
            ->call('saveCalls')
            ->assertHasErrors('callMessage');

        $this->assertSame(CallSettings::DEFAULT_MESSAGE, CallSettings::message());
    }

    #[DataProvider('switchValues')]
    public function test_texto_vazio_e_recusado_depois_de_alternar_a_chave(mixed $on): void
    {
        Livewire::test(Channels::class)
            ->set('rejectCalls', false)
            ->set('rejectCalls', $on)
            ->set('callMessage', '')
            ->call('saveCalls')
            ->assertHasErrors('callMessage');

        $this->assertSame(CallSettings::DEFAULT_MESSAGE, CallSettings::message());
    }

    /** @return array<string, array{mixed}> */
    public static function switchValues(): array
    {
        return ['bool' => [true], 'inteiro' => [1], 'string 1' => ['1'], 'string true' => ['true'], 'on' => ['on']];
    }

    public function test_chave_desligada_aceita_texto_vazio(): void
    {
        Livewire::test(Channels::class)
            ->set('rejectCalls', false)
            ->set('callMessage', '')
            ->call('saveCalls')
            ->assertHasNoErrors()
            ->assertSet('callMessage', CallSettings::DEFAULT_MESSAGE);

        $this->assertFalse(CallSettings::rejects());
    }

    public function test_nao_admin_recebe_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]));

        Livewire::test(Channels::class)->call('saveCalls')->assertForbidden();
    }

    public function test_validacao_que_falha_limpa_o_aviso_anterior(): void
    {
        Livewire::test(Channels::class)
            ->call('saveCalls')
            ->assertSet('callsNotice', 'Configuração salva. Nenhum canal conectado agora; ela vale quando um canal conectar.')
            ->set('callMessage', '')
            ->call('saveCalls')
            ->assertHasErrors('callMessage')
            ->assertSet('callsNotice', '');
    }

    public function test_sem_canal_conectado_avisa_que_vale_ao_conectar(): void
    {
        Livewire::test(Channels::class)
            ->call('saveCalls')
            ->assertSet('callsNotice', 'Configuração salva. Nenhum canal conectado agora; ela vale quando um canal conectar.');
    }
}
