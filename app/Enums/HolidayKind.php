<?php

namespace App\Enums;

enum HolidayKind: string
{
    case NACIONAL = 'nacional';
    case ESTADUAL = 'estadual';
    case MUNICIPAL = 'municipal';
    case FACULTATIVO = 'facultativo';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::NACIONAL => 'Nacional',
            self::ESTADUAL => 'Estadual',
            self::MUNICIPAL => 'Municipal',
            self::FACULTATIVO => 'Facultativo',
            self::MANUAL => 'Cadastrado à mão',
        };
    }

    /** Tom do selo (`x-status-badge`). Sem `default`: caso novo precisa de tom. */
    public function tone(): string
    {
        return match ($this) {
            self::NACIONAL => 'neutral',
            self::ESTADUAL => 'neutral',
            self::MUNICIPAL => 'neutral',
            self::FACULTATIVO => 'warn',
            self::MANUAL => 'neutral',
        };
    }
}
