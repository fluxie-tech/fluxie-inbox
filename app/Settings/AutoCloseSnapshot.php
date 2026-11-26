<?php

namespace App\Settings;

/** Os ajustes do encerramento automático lidos de uma vez, para uma rodada inteira decidir sem voltar ao banco a cada chamado. */
final readonly class AutoCloseSnapshot
{
    public function __construct(
        public bool $enabled,
        public int $idleMinutes,
        public bool $warnEnabled,
        public string $warnMessage,
        public int $closeAfterWarnMinutes,
        public bool $farewellEnabled,
        public string $farewellMessage,
        public bool $businessHoursOnly,
    ) {}
}
