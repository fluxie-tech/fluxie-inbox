<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Backup\Restore\RestoreProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->withoutVite();
    }

    public function test_404_em_portugues_com_volta_ao_inicio(): void
    {
        $this->get('/nao-existe')->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('Voltar ao início');
    }

    public function test_403(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->get('/auditoria/sistema')->assertForbidden()->assertSee('Acesso negado');
    }

    public function test_419(): void
    {
        $this->assertStringContainsString('Sessão expirada', view('errors.419')->render());
    }

    public function test_500_renderiza_sem_banco(): void
    {
        $padrao = config('database.default');
        config(['database.default' => 'nada']);

        try {
            $this->assertStringContainsString('Algo deu errado', view('errors.500')->render());
        } finally {
            config(['database.default' => $padrao]); // o rollback do RefreshDatabase precisa da conexão de volta
        }
    }

    public function test_503_mantem_as_duas_mensagens(): void
    {
        foreach ([[true, 'Restauração do banco em andamento'], [false, 'Sistema em manutenção']] as [$running, $texto]) {
            $this->instance(RestoreProgress::class, Mockery::mock(RestoreProgress::class, fn ($m) => $m->shouldReceive('isRunning')->andReturn($running)));

            $html = view('errors.503')->render();

            $this->assertStringContainsString($texto, $html);
            $this->assertStringContainsString('http-equiv="refresh"', $html);
        }
    }
}
