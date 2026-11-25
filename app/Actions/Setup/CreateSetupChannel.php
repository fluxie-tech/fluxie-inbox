<?php

namespace App\Actions\Setup;

use App\Actions\Channels\CreateChannel;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\DefaultWorkflow;
use App\Services\Bot\WorkflowPublisher;
use App\Services\Setup\ApplySegment;
use App\Services\Setup\SetupState;
use Illuminate\Support\Facades\DB;

/** Cria o canal do assistente de primeiro acesso: com o atendimento padrão na distribuidora e a marca para retomar. */
final class CreateSetupChannel
{
    public function __construct(
        private readonly CreateChannel $create,
        private readonly WorkflowPublisher $publisher,
    ) {}

    public function __invoke(string $sessionName, ?User $by): Channel
    {
        // A marca do canal entra junto com ele: sem ela, a retomada criaria um segundo canal.
        return DB::transaction(function () use ($sessionName, $by): Channel {
            $channel = ($this->create)($sessionName, $by);

            if (SetupState::segment() === ApplySegment::DISTRIBUIDORA) {
                $workflow = Workflow::query()->where('name', DefaultWorkflow::NAME)->first();

                if ($workflow !== null) {
                    $this->publisher->activate($workflow, $channel);
                }
            }

            Setting::put(SetupState::CHANNEL_KEY, $channel->id, 'int');

            return $channel;
        });
    }
}
