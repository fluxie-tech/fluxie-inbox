<?php

namespace Tests\Feature\AutoClose;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Settings\AutoCloseSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoCloseSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_padroes_e_valores_fora_da_faixa_voltam_ao_padrao(): void
    {
        $this->assertTrue(AutoCloseSettings::enabled());
        $this->assertSame(1440, AutoCloseSettings::idleMinutes());
        $this->assertFalse(AutoCloseSettings::warnEnabled());
        $this->assertSame(AutoCloseSettings::DEFAULT_WARN, AutoCloseSettings::warnMessage());
        $this->assertSame(60, AutoCloseSettings::closeAfterWarnMinutes());
        $this->assertFalse(AutoCloseSettings::farewellEnabled());
        $this->assertSame(AutoCloseSettings::DEFAULT_FAREWELL, AutoCloseSettings::farewellMessage());
        $this->assertFalse(AutoCloseSettings::businessHoursOnly());

        AutoCloseSettings::save(['enabled' => false, 'idle_minutes' => 90, 'warn_enabled' => true, 'close_after_warn_minutes' => 30, 'farewell_enabled' => true, 'business_hours_only' => true, 'warn_message' => 'Oi', 'farewell_message' => 'Tchau']);

        $this->assertFalse(AutoCloseSettings::enabled());
        $this->assertSame(90, AutoCloseSettings::idleMinutes());
        $this->assertTrue(AutoCloseSettings::warnEnabled());
        $this->assertSame(30, AutoCloseSettings::closeAfterWarnMinutes());
        $this->assertTrue(AutoCloseSettings::farewellEnabled());
        $this->assertTrue(AutoCloseSettings::businessHoursOnly());
        $this->assertSame('Oi', AutoCloseSettings::warnMessage());
        $this->assertSame('Tchau', AutoCloseSettings::farewellMessage());

        AutoCloseSettings::save(['idle_minutes' => 14, 'close_after_warn_minutes' => 10081, 'warn_message' => str_repeat('a', 501)]);
        $this->assertSame(1440, AutoCloseSettings::idleMinutes());
        $this->assertSame(60, AutoCloseSettings::closeAfterWarnMinutes());
        $this->assertSame(AutoCloseSettings::DEFAULT_WARN, AutoCloseSettings::warnMessage());
        $this->assertSame(43200, $this->idleAfter(43200));
        $this->assertSame(1440, $this->idleAfter(43201));

        AutoCloseSettings::save(['enabled' => 'sim', 'idle_minutes' => 'muito']);
        $this->assertTrue(AutoCloseSettings::enabled());
        $this->assertSame(1440, AutoCloseSettings::idleMinutes());
        $this->assertSame('Tchau', AutoCloseSettings::farewellMessage());
    }

    private function idleAfter(int $minutes): int
    {
        AutoCloseSettings::save(['idle_minutes' => $minutes]);

        return AutoCloseSettings::idleMinutes();
    }

    public function test_render_troca_nome_e_protocolo_e_texto_vazio_usa_o_padrao(): void
    {
        $ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id,
            'contact_id' => Contact::factory()->create(['name' => 'Maria'])->id,
        ]);

        $this->assertSame("Oi, Maria! {$ticket->protocol}", AutoCloseSettings::render('Oi, {{nome}}! {{protocolo}}', $ticket));
        $this->assertSame(
            "Olá, Maria! Ainda está por aí? Se não precisar de mais nada, vamos encerrar o atendimento {$ticket->protocol} em breve.",
            AutoCloseSettings::render('', $ticket),
        );
    }
}
