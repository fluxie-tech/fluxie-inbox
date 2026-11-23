<?php

namespace Tests\Feature\Backup\Restore;

use App\Services\Backup\Restore\RestoreProgress;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RestoreProgressTest extends TestCase
{
    public function test_get_sem_estado_devolve_idle(): void
    {
        $state = (new RestoreProgress)->get();

        $this->assertSame('idle', $state['status']);
        $this->assertSame([], $state['steps']);
        $this->assertFalse((new RestoreProgress)->isRunning());
    }

    public function test_begin_guarda_o_kind_e_get_devolve(): void
    {
        $progress = new RestoreProgress;
        $progress->begin('Zerando o sistema', ['reset-down'], 'reset');

        $this->assertSame('reset', $progress->get()['kind']);
        $this->assertSame('Entrando em manutenção', $progress->get()['steps'][0]['label']);

        $progress->begin('Restaurando o banco', ['inspect']);
        $this->assertSame('restore', $progress->get()['kind']);
    }

    public function test_estado_sem_kind_ou_idle_vale_restore(): void
    {
        $this->assertSame('restore', (new RestoreProgress)->get()['kind']);

        $legacy = ['status' => 'running', 'title' => 'T', 'steps' => [], 'message' => ''];
        Cache::put('restore:state', $legacy);
        $this->assertSame('restore', (new RestoreProgress)->get()['kind']);

        Cache::put('restore:state', [...$legacy, 'kind' => 'bobagem']);
        $this->assertSame('restore', (new RestoreProgress)->get()['kind']);
    }

    public function test_replace_regrava_o_estado(): void
    {
        $progress = new RestoreProgress;
        $progress->begin('Zerando o sistema', ['reset-down', 'reset-up'], 'reset');
        $progress->running('reset-down');
        $state = $progress->get();

        Cache::flush();
        $this->assertSame('idle', $progress->get()['status']);

        $progress->replace($state);
        $this->assertSame($state, $progress->get());
        $this->assertTrue($progress->isRunning());
    }

    public function test_replace_de_estado_terminado_usa_o_ttl_de_terminado(): void
    {
        $progress = new RestoreProgress;
        $progress->begin('Zerando o sistema', ['reset-down'], 'reset');
        $progress->finish('ok');
        $state = $progress->get();
        Cache::flush();

        $progress->replace($state);

        $this->travel(2)->hours();
        $this->assertSame('done', $progress->get()['status']);
    }

    public function test_comeca_ordena_e_termina_os_passos(): void
    {
        $progress = new RestoreProgress;

        $progress->begin('Restaurando o banco', ['inspect', 'safety', 'swap']);
        $this->assertTrue($progress->isRunning());
        $this->assertSame(['pending', 'pending', 'pending'], array_column($progress->get()['steps'], 'state'));
        $this->assertSame(['inspect', 'safety', 'swap'], array_column($progress->get()['steps'], 'key'));
        $this->assertSame('Conferindo o arquivo', $progress->get()['steps'][0]['label']);

        $progress->running('inspect');
        $this->assertSame(['running', 'pending', 'pending'], array_column($progress->get()['steps'], 'state'));

        $progress->done('inspect');
        $progress->running('safety');
        $progress->done('safety');
        $progress->running('swap');
        $progress->done('swap');
        $progress->finish('Restauração concluída.');

        $state = $progress->get();
        $this->assertSame('done', $state['status']);
        $this->assertSame(['done', 'done', 'done'], array_column($state['steps'], 'state'));
        $this->assertSame('Restauração concluída.', $state['message']);
        $this->assertSame('Restaurando o banco', $state['title']);
        $this->assertFalse($progress->isRunning());
    }

    public function test_falha_marca_o_passo_e_guarda_a_mensagem(): void
    {
        $progress = new RestoreProgress;
        $progress->begin('Restaurando o banco', ['inspect', 'safety']);
        $progress->running('inspect');
        $progress->done('inspect');
        $progress->running('safety');

        $progress->fail('Há um backup em andamento.');

        $state = $progress->get();
        $this->assertSame('failed', $state['status']);
        $this->assertSame(['done', 'failed'], array_column($state['steps'], 'state'));
        $this->assertSame('Há um backup em andamento.', $state['message']);
        $this->assertFalse($progress->isRunning());
    }

    public function test_o_estado_em_andamento_expira_em_1_hora_e_o_final_em_1_dia(): void
    {
        // O TTL é conferido na gravação: expirar de verdade com `travel()` só funciona no cache em memória, não no Redis do CI.
        Cache::spy();
        $progress = new RestoreProgress;

        $progress->begin('Restaurando o banco', ['inspect']);
        $progress->finish('Restauração concluída.');

        Cache::shouldHaveReceived('put')->withArgs(fn (string $key, array $state, \DateTimeInterface $until): bool => $key === 'restore:state'
            && $state['status'] === 'running'
            && $until >= now()->addMinutes(59) && $until <= now()->addMinutes(61))->once();
        Cache::shouldHaveReceived('put')->withArgs(fn (string $key, array $state, \DateTimeInterface $until): bool => $key === 'restore:state'
            && $state['status'] === 'done'
            && $until >= now()->addHours(23) && $until <= now()->addHours(25))->once();
    }

    public function test_um_estado_que_expirou_no_cache_volta_a_idle(): void
    {
        (new RestoreProgress)->begin('Restaurando o banco', ['inspect']);

        Cache::forget('restore:state');

        $this->assertFalse((new RestoreProgress)->isRunning());
        $this->assertSame('idle', (new RestoreProgress)->get()['status']);
    }
}
