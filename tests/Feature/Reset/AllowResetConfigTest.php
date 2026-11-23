<?php

namespace Tests\Feature\Reset;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllowResetConfigTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function values(): array
    {
        return ['true' => ['true', true], '1' => ['1', true], 'on' => ['on', true], 'false' => ['false', false], '0' => ['0', false], 'off' => ['off', false], 'no' => ['no', false], 'lixo' => ['talvez', false]];
    }

    #[DataProvider('values')]
    public function test_a_variavel_so_liga_com_um_valor_booleano_verdadeiro(string $value, bool $expected): void
    {
        $_ENV['FLUXIE_ALLOW_RESET'] = $_SERVER['FLUXIE_ALLOW_RESET'] = $value;

        try {
            $config = require config_path('fluxie.php');
        } finally {
            unset($_ENV['FLUXIE_ALLOW_RESET'], $_SERVER['FLUXIE_ALLOW_RESET']);
        }

        $this->assertSame($expected, $config['allow_reset']);
    }
}
