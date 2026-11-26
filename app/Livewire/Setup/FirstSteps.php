<?php

namespace App\Livewire\Setup;

use App\Actions\Setup\CreateSetupChannel;
use App\Livewire\Concerns\ReceivesAvatar;
use App\Models\Channel;
use App\Services\Media\AvatarImage;
use App\Services\Setup\ApplySegment;
use App\Services\Setup\SetupState;
use App\Settings\CompanySettings;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Os três passos do admin recém-criado: empresa, segmento e canal. Concluir leva à fila. */
#[Title('Primeiros passos')]
#[Layout('layouts::setup')]
class FirstSteps extends Component
{
    use ReceivesAvatar;

    public string $name = '';

    public string $sessionName = 'principal';

    public ?int $channelId = null;

    public string $step = 'empresa';

    public function mount(): void
    {
        $this->guard();

        $this->name = SetupState::companyName();
        $this->step = SetupState::step();
        $this->channelId = $this->step === 'canal' ? SetupState::channelId() : null;
    }

    public function saveLogo(): void
    {
        $this->guard();

        CompanySettings::setLogo($this->avatarWebp(AvatarImage::SIZE_LOGO));
    }

    public function removeLogo(): void
    {
        $this->guard();

        CompanySettings::clearLogo();
    }

    public function saveCompany(): void
    {
        $this->guard();

        $this->validate(['name' => ['required', 'string', 'max:120']], [
            'name.required' => 'Informe o nome da empresa.',
            'name.max' => 'O nome pode ter até 120 caracteres.',
        ]);

        CompanySettings::save(['name' => $this->name]);
        SetupState::setStep('segmento');
        $this->step = 'segmento';
    }

    public function chooseSegment(string $segment): void
    {
        $this->guard();

        if (SetupState::step() !== 'segmento') {
            return;
        }

        $user = auth()->user() ?? abort(403);

        try {
            app(ApplySegment::class)($segment, $user);
        } catch (InvalidArgumentException $e) {
            $this->addError('segment', $e->getMessage());

            return;
        }

        $this->step = SetupState::step();
    }

    public function createChannel(CreateSetupChannel $create): void
    {
        $this->guard();

        if ($this->channelId !== null || SetupState::step() !== 'canal') {
            return;
        }

        $this->channelId = $create($this->sessionName, auth()->user())->id;
    }

    /** @param  array<string, mixed>  $payload */
    #[On('echo-private:inbox,.channel.status')]
    public function onChannelStatus(array $payload = []): void
    {
        if (SetupState::concluded()) {
            return;
        }

        if ($this->channelId === null || ($payload['channel_id'] ?? null) !== $this->channelId) {
            $this->skipRender();

            return;
        }

        if (Channel::query()->find($this->channelId)?->isConnected()) {
            $this->finish();
        }
    }

    public function skip(): void
    {
        $this->guard();

        $this->finish();
    }

    public function render(): View
    {
        return view('livewire.setup.first-steps', [
            'channel' => $this->channelId === null ? null : Channel::query()->find($this->channelId),
        ]);
    }

    private function finish(): void
    {
        SetupState::markConcluded();
        $this->redirectRoute('inbox');
    }

    private function guard(): void
    {
        abort_if(SetupState::concluded(), 404);
        $this->authorize('admin');
    }
}
