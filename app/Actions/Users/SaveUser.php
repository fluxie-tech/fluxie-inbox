<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Grava o usuário do formulário de Configurações e audita o que mudou (nunca a senha). A tela autoriza e valida antes. */
class SaveUser
{
    public const MESSAGES = [
        'name.required' => 'Informe o nome.',
        'email.required' => 'Informe o e-mail.',
        'email.email' => 'E-mail inválido.',
        'email.unique' => 'Já existe um usuário com esse e-mail.',
        'password.required' => 'Defina uma senha.',
        'password.min' => 'A senha precisa ter ao menos 8 caracteres.',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?User $target): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target?->id)],
            'password' => [$target === null ? 'required' : 'nullable', 'string', Password::default()],
            'sectorIds' => ['array'],
            'sectorIds.*' => ['integer', Rule::exists('sectors', 'id')],
        ];
    }

    /**
     * @param  array{name: string, email: string, can_transfer: bool, password: string}  $data
     * @param  array<int, int>  $sectorIds
     */
    public function __invoke(?User $target, UserRole $role, array $data, array $sectorIds, User $actor): void
    {
        $creating = $target === null;
        $user = $target ?? new User;

        $user->forceFill([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'role' => $role,
            'can_transfer' => $data['can_transfer'],
        ]);

        if ($creating) {
            $user->forceFill(['active' => true, 'email_verified_at' => now()]);
        }

        if ($data['password'] !== '') {
            $user->password = $data['password'];
        }

        // O que mudou só se sabe antes de gravar.
        $dirty = array_keys($user->getDirty());
        $oldRole = $creating ? null : $user->getOriginal('role');
        $sectorChanges = [];

        DB::transaction(function () use ($user, $sectorIds, &$sectorChanges): void {
            $user->save();
            $sectorChanges = $user->sectors()->sync($sectorIds);
        });

        $sectorsChanged = ($sectorChanges['attached'] ?? []) !== [] || ($sectorChanges['detached'] ?? []) !== [];

        $this->audit($actor, $user, $creating, $dirty, $oldRole, $sectorsChanged);
    }

    /**
     * Criar grava só a criação; editar grava os campos alterados, a mudança de papel e a definição de senha
     * (nunca a senha). Sem mudança nenhuma, não grava.
     *
     * @param  list<string>  $dirty
     */
    private function audit(User $actor, User $user, bool $creating, array $dirty, mixed $oldRole, bool $sectorsChanged): void
    {
        if ($creating) {
            Auditor::record(AuditAction::UserCreated, $actor, $user->name, ['role' => $user->role->value]);

            return;
        }

        $fields = [...array_values(array_intersect(['name', 'email', 'can_transfer'], $dirty)), ...($sectorsChanged ? ['sectors'] : [])];

        if ($fields !== []) {
            Auditor::record(AuditAction::UserUpdated, $actor, $user->name, ['fields' => $fields]);
        }

        if (in_array('role', $dirty, true) && $oldRole instanceof UserRole) {
            Auditor::record(AuditAction::UserRoleChanged, $actor, $user->name, ['from' => $oldRole->value, 'to' => $user->role->value]);
        }

        if (in_array('password', $dirty, true)) {
            Auditor::record(AuditAction::UserPasswordSet, $actor, $user->name);
        }
    }
}
