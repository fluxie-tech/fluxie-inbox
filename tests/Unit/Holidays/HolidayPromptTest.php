<?php

namespace Tests\Unit\Holidays;

use App\Services\Holidays\HolidayPrompt;
use PHPUnit\Framework\TestCase;

class HolidayPromptTest extends TestCase
{
    public function test_monta_o_prompt_com_cidade_uf_e_periodo(): void
    {
        $prompt = HolidayPrompt::for('Ribeirão Preto', 'SP', '2026-11-26', '2027-12-31');

        $this->assertStringContainsString('de Ribeirão Preto/SP, Brasil, entre 26/11/2026 e 31/12/2027', $prompt);
        $this->assertStringContainsString('estaduais de SP', $prompt);
        $this->assertStringContainsString('municipais de Ribeirão Preto', $prompt);
        $this->assertStringContainsString('"tipo": "nacional"', $prompt);
    }
}
