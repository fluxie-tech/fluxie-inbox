<?php

namespace App\Livewire\Settings;

use App\Actions\Users\SaveUser;
use App\Enums\AuditAction;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Concerns\ReceivesAvatar;
use App\Models\Sector;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Media\AvatarImage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Usuários')]
class Users extends Component
{
    public bool $showForm = false;

    use ReceivesAvatar;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'atendente';

    public string $password = '';

    /** @var array<int, int> */
    public array $sectorIds = [];

    public bool $canTransfer = false;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function newUser(): void
    {
        $this->authorize('viewAny', User::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $target = User::query()->findOrFail($id);
        $this->authorize('update', $target);

        $this->resetForm();
        $this->showForm = true;
        $this->editingId = $target->id;
        $this->name = $target->name;
        $this->email = $target->email;
        $this->role = $target->role->value;
        $this->canTransfer = $target->can_transfer;
        $this->sectorIds = $target->sectors()->get()->map(fn (Sector $sector): int => $sector->id)->all();
    }

    public function saveAvatar(): void
    {
        $this->avatarTarget()->setAvatar($this->avatarWebp(AvatarImage::SIZE_PERSON));
    }

    public function removeAvatar(): void
    {
        $this->avatarTarget()->clearAvatar();
    }

    /** A foto é de quem está aberto no formulário; quem não pode editar essa pessoa também não mexe na foto dela. */
    private function avatarTarget(): User
    {
        $this->authorize('viewAny', User::class);

        if ($this->editingId === null) {
            throw ValidationException::withMessages(['photo' => 'Salve o usuário antes de escolher a foto.']);
        }

        $target = User::query()->findOrFail($this->editingId);
        $this->authorize('update', $target);

        return $target;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(SaveUser $save): void
    {
        $this->authorize('viewAny', User::class);

        $role = UserRole::tryFrom($this->role);

        if ($role === null) {
            $this->addError('role', 'Papel inválido.');

            return;
        }

        $target = $this->editingId === null ? null : User::query()->findOrFail($this->editingId);

        $this->authorizeSave($target, $role);
        $this->validate(SaveUser::rules($target), SaveUser::MESSAGES);

        if ($target?->isLastActiveAdmin() && $role !== UserRole::ADMIN) {
            $this->addError('role', 'Este é o único administrador ativo. Promova outra pessoa antes de rebaixá-lo.');

            return;
        }

        $save($target, $role, [
            'name' => $this->name,
            'email' => $this->email,
            'can_transfer' => $this->canTransfer,
            'password' => $this->password,
        ], $this->sectorIds, $this->user());
        $this->resetForm();
    }

    /** Criar vale pelo papel pedido; editar vale pelo papel de quem está sendo editado e, se o papel muda, pelo novo. */
    private function authorizeSave(?User $target, UserRole $role): void
    {
        if ($target === null) {
            $this->authorize('create', [User::class, $role]);

            return;
        }

        $this->authorize('update', $target);

        if ($role !== $target->role) {
            $this->authorize('assignRole', [User::class, $role]);
        }
    }

    public function toggleActive(int $id): void
    {
        $target = User::query()->findOrFail($id);

        if ($target->active) {
            $this->authorize('deactivate', $target);
            $target->forceFill(['active' => false])->save();

            // O login já recusa quem está inativo; apagar as sessões derruba quem estava logado agora.
            DB::table('sessions')->where('user_id', $target->id)->delete();
            Auditor::record(AuditAction::UserDeactivated, $this->user(), $target->name);

            return;
        }

        $this->authorize('update', $target);
        $target->forceFill(['active' => true])->save();
        Auditor::record(AuditAction::UserActivated, $this->user(), $target->name);
    }

    /**
     * @return list<UserRole>
     */
    private function allowedRoles(): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $role): bool => $this->user()->can('create', [User::class, $role])));
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'email', 'role', 'password', 'sectorIds', 'canTransfer');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.settings.users', [
            'editing' => $this->editingId === null ? null : User::query()->find($this->editingId),
            'users' => User::query()
                ->with('sectors')
                ->withCount(['assignedTickets as open_tickets_count' => fn (Builder $query) => $query->where('status', '<>', TicketStatus::ENCERRADO->value)])
                ->orderByDesc('active')
                ->orderBy('name')
                ->get(),
            'sectors' => Sector::query()->orderBy('menu_order')->get(),
            'roles' => $this->allowedRoles(),
        ]);
    }
}
