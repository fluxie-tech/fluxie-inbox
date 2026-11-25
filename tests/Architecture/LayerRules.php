<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Services\Waha\WahaClient;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/** Regras de camadas conferidas pelo PHPStan (sem sufixo Test, o PHPUnit não as pega). */
final class LayerRules
{
    public function test_models_nao_dependem_da_camada_web(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Models'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Livewire'), Selector::inNamespace('App\Http'))
            ->because('Models ficam abaixo da camada web e não conhecem Livewire nem controllers.');
    }

    public function test_services_e_actions_nao_dependem_da_camada_web(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Services'), Selector::inNamespace('App\Actions'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Livewire'), Selector::inNamespace('App\Http'))
            ->because('Serviços e ações são chamados pela camada web, nunca o contrário.');
    }

    public function test_enums_e_support_ficam_na_base(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Enums'), Selector::inNamespace('App\Support'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace('App\Livewire'),
                Selector::inNamespace('App\Http'),
                Selector::inNamespace('App\Services'),
            )
            ->because('Enums e helpers de apoio são a base e não dependem de camadas acima.');
    }

    public function test_so_servicos_e_jobs_falam_com_o_waha_client(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::inNamespace('App\Services'),
                Selector::inNamespace('App\Actions'),
                Selector::inNamespace('App\Jobs'),
                Selector::inNamespace('App\Console'),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(WahaClient::class))
            ->because('O WahaClient é detalhe de transporte: a UI e os models usam os serviços do WAHA.');
    }
}
