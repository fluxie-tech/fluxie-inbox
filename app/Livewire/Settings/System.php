<?php

namespace App\Livewire\Settings;

use App\Actions\Update\RequestUpdate;
use App\Services\Reset\ResetSystem;
use App\Services\Update\Preflight;
use App\Services\Update\ReleaseFeed;
use App\Services\Update\UpdateGate;
use App\Services\Update\UpdateOverview;
use App\Settings\CompanySettings;
use App\Settings\UpdateSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sistema')]
class System extends Component
{
    public string $panelUrl = '';

    /** Só existe entre a digitação e o salvar: o valor guardado nunca volta para a tela (nem no snapshot). */
    public string $tokenInput = '';

    public bool $confirming = false;

    public string $confirm = '';

    public bool $composeEdited = false;

    public ?string $problem = null;

    public bool $resetting = false;

    public bool $resetAgree = false;

    public string $resetName = '';

    /** Só existe entre a digitação e a conferência: sai do snapshot a cada tentativa. */
    public string $resetPassword = '';

    public ?string $resetProblem = null;

    public ?string $connectionMessage = null;

    /** @var list<string> */
    public array $connectionErrors = [];

    public function mount(): void
    {
        $this->authorize('admin');
        $this->panelUrl = UpdateSettings::panelUrl() ?? '';
    }

    public function saveConnection(): void
    {
        $this->authorize('admin');
        $token = trim($this->tokenInput);

        try {
            $this->validate(['panelUrl' => ['nullable', 'url', 'max:255'], 'tokenInput' => ['nullable', 'string', 'max:500']], [
                'panelUrl.url' => 'Informe a URL completa do painel, com https://.',
            ]);
        } finally {
            // Sai do snapshot mesmo com erro de validação: senão o segredo digitado volta para a tela.
            $this->reset('tokenInput');
        }

        UpdateSettings::setPanelUrl(rtrim(trim($this->panelUrl), '/') ?: null);

        if ($token !== '') {
            UpdateSettings::setToken($token);
        }

        $this->reset('connectionErrors', 'connectionMessage');
        $this->panelUrl = UpdateSettings::panelUrl() ?? '';
    }

    public function removeToken(): void
    {
        $this->authorize('admin');
        UpdateSettings::setToken(null);
        $this->reset('tokenInput', 'connectionErrors', 'connectionMessage');
    }

    public function toggleCheck(): void
    {
        $this->authorize('admin');
        UpdateSettings::setCheckEnabled(! UpdateSettings::checkEnabled());
    }

    public function checkNow(ReleaseFeed $feed): void
    {
        $this->authorize('admin');
        $feed->refresh();
    }

    public function testConnection(Preflight $preflight, UpdateGate $gate): void
    {
        $this->authorize('admin');

        if (! $gate->canUpdate()) {
            return;
        }

        $this->connectionErrors = $preflight->connection();
        $this->connectionMessage = $this->connectionErrors === [] ? 'Conexão com o Coolify funcionando.' : null;
    }

    /** Abre a confirmação; o aviso de compose editado faz rede, por isso só aqui e não no render. */
    public function askUpdate(Preflight $preflight, UpdateGate $gate): void
    {
        $this->authorize('admin');
        $this->reset('confirm', 'problem');
        $this->problem = $gate->blocker();

        if ($this->problem !== null) {
            return;
        }

        $this->composeEdited = $preflight->composeEdited();
        $this->confirming = true;
    }

    public function cancelUpdate(): void
    {
        $this->authorize('admin');
        $this->reset('confirming', 'confirm', 'problem', 'composeEdited');
    }

    public function update(RequestUpdate $request): void
    {
        $this->authorize('admin');
        $this->problem = $request(Auth::user(), $this->confirm);

        if ($this->problem === null) {
            $this->cancelUpdate();
        }
    }

    /** Abre a confirmação do zerar; o bloqueio faz consultas (WAHA), por isso só aqui e não no render. */
    public function askReset(): void
    {
        $this->authorize('admin');

        if (config('fluxie.allow_reset') !== true) {
            return;
        }

        $this->reset('resetAgree', 'resetName', 'resetPassword');
        $this->resetProblem = app(ResetSystem::class)->problem();
        $this->resetting = true;
    }

    public function cancelReset(): void
    {
        $this->authorize('admin');
        $this->reset('resetting', 'resetAgree', 'resetName', 'resetPassword', 'resetProblem');
    }

    public function resetSystem(): void
    {
        $this->authorize('admin');
        $user = Auth::user() ?? abort(403);
        $key = 'reset-system:'.$user->id;
        $password = $this->resetPassword;
        $this->reset('resetPassword');

        $this->resetProblem = match (true) {
            ! $this->resetAgree => 'Marque a caixa para confirmar.',
            ! $this->nameMatches() => 'Digite o nome da empresa exatamente como está cadastrado.',
            RateLimiter::tooManyAttempts($key, 5) => 'Muitas tentativas. Espere '.RateLimiter::availableIn($key).' segundos.',
            default => $this->checkPassword($key, $password, $user->getAuthPassword()),
        };

        if ($this->resetProblem !== null) {
            return;
        }

        // Trinco curto: dois cliques seguidos passam juntos pelo `problem()` antes de o primeiro marcar o andamento.
        $latch = Cache::lock('reset-system-dispatch', 120);

        if (! $latch->get()) {
            $this->resetProblem = 'O zerar já foi pedido.';

            return;
        }

        try {
            $reset = app(ResetSystem::class);
            $this->resetProblem = $reset->problem();

            if ($this->resetProblem !== null) {
                return;
            }

            $reset->start($user->name.' <'.$user->email.'>');
        } finally {
            $latch->release();
        }

        $this->redirectRoute('restauracao');
    }

    private function checkPassword(string $key, string $password, string $hash): ?string
    {
        if (Hash::check($password, $hash)) {
            RateLimiter::clear($key);

            return null;
        }

        RateLimiter::hit($key, 60);

        return 'Senha incorreta.';
    }

    private function nameMatches(): bool
    {
        return mb_strtolower(trim($this->resetName)) === mb_strtolower($this->resetWord());
    }

    /** A palavra a digitar: o nome da empresa sem espaços nas pontas, ou ZERAR se ela não tiver nome. */
    private function resetWord(): string
    {
        return trim(CompanySettings::name()) ?: 'ZERAR';
    }

    public function render(UpdateOverview $overview): View
    {
        return view('livewire.settings.system', $overview->forView(config()->string('app.display_timezone')) + [
            'allowReset' => config('fluxie.allow_reset') === true,
            'resetWord' => $this->resetWord(),
            'resetNameOk' => $this->nameMatches(),
        ]);
    }
}
