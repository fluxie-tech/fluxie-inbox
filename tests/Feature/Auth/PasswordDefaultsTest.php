<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/** A regra de senha padrão (usuários, Minha conta, redefinição) nunca consulta serviço de terceiros. */
class PasswordDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn (): string => 'production');
        Http::fake();
    }

    public function test_em_producao_valida_sem_consultar_servico_externo(): void
    {
        $this->assertTrue(Validator::make(['password' => 'Senha-forte-123!'], ['password' => Password::default()])->passes());
        // A checagem de vazamento falha aberta (erro de rede vira "passa"): só a ausência de pedido prova que não há consulta.
        Http::assertNothingSent();
    }

    public function test_em_producao_continua_exigindo_maiuscula_numero_e_simbolo(): void
    {
        $this->assertTrue(Validator::make(['password' => 'senhasimples'], ['password' => Password::default()])->fails());
    }
}
