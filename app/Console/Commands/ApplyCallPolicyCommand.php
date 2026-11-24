<?php

namespace App\Console\Commands;

use App\Services\Waha\WahaCalls;
use Illuminate\Console\Command;

class ApplyCallPolicyCommand extends Command
{
    protected $signature = 'app:apply-call-policy';

    protected $description = 'Confere a recusa de ligações em cada sessão ativa do WAHA e corrige as que divergem';

    /** Sai com sucesso mesmo com falhas: a rodada do dia seguinte corrige. */
    public function handle(WahaCalls $calls): int
    {
        $result = $calls->applyAll();

        $this->info("aplicados: {$result['applied']}, sem mudança: {$result['unchanged']}, falhas: {$result['failed']}");

        return self::SUCCESS;
    }
}
