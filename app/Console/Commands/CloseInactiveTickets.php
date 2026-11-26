<?php

namespace App\Console\Commands;

use App\Services\AutoClose\AutoCloseRun;
use Illuminate\Console\Command;

class CloseInactiveTickets extends Command
{
    protected $signature = 'app:close-inactive-tickets';

    protected $description = 'Avisa e encerra os chamados parados, pelos prazos do encerramento automático';

    public function handle(AutoCloseRun $run): int
    {
        $done = $run();

        $this->info("Avisos: {$done['warned']}. Encerrados: {$done['closed']}.");

        return self::SUCCESS;
    }
}
