<?php

namespace Tests\Feature\Console;

use App\Models\Channel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApplyCallPolicyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_em_todos_os_canais_ativos_e_resume(): void
    {
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'k']);
        Http::fake([
            'GET */api/apps*' => Http::response([], 200),
            '*/api/apps*' => Http::response([], 201),
        ]);
        Channel::factory()->connected()->create(['waha_session' => 'um']);
        Channel::factory()->connected()->create(['waha_session' => 'dois']);

        $this->artisan('app:apply-call-policy')->expectsOutputToContain('aplicados: 2')->assertSuccessful();
    }

    public function test_esta_agendado_todo_dia(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'app:apply-call-policy')));
    }
}
