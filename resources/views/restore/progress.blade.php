@php
    $reset = $state['kind'] === 'reset';
    $back = $reset ? route('instalar') : route('configuracoes.backups');
@endphp
<!DOCTYPE html>
{{-- Sem `CompanySettings`, `@auth` ou qualquer consulta ao banco: esta página precisa abrir durante a troca do banco. --}}
<html lang="pt-BR">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ $reset ? 'Zerar o sistema' : 'Restauração do banco' }} - {{ config('app.name') }}</title>
        <meta name="restore-state-url" content="{{ route('restauracao.estado') }}">
        <meta name="restore-done-url" content="{{ $back }}">
        @fonts
        @fluxAppearance
        @vite(['resources/css/app.css', 'resources/js/restore-progress.js'])
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <main data-restore-progress class="mx-auto flex min-h-svh w-full max-w-xl flex-col justify-center gap-6 p-6">
            <h1 data-title class="text-lg font-semibold">{{ $state['status'] === 'idle' ? 'Restauração do banco' : $state['title'] }}</h1>

            <ol data-steps class="divide-y divide-line rounded-[14px] border border-line bg-surface text-sm">
                @foreach ($state['steps'] as $step)
                    <li data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}" class="flex items-center justify-between gap-4 px-4 py-3">
                        <span class="text-ink">{{ $step['label'] }}</span>
                        <span data-marker class="font-mono text-xs {{ match ($step['state']) { 'done' => 'text-ok', 'failed' => 'text-danger', 'running' => 'text-accent-content', default => 'text-ink-3' } }}">
                            {{ match ($step['state']) { 'done' => 'feito', 'failed' => 'falhou', 'running' => 'em andamento…', default => 'aguardando' } }}
                        </span>
                    </li>
                @endforeach
            </ol>

            <p data-message role="status" class="text-sm text-ink-2">
                @if ($state['status'] === 'idle')
                    Nenhuma restauração em andamento.
                @else
                    {{ $state['message'] }}
                @endif
            </p>

            <p data-reset-hint @class(['text-sm text-ink-2', 'hidden' => ! ($reset && $state['status'] === 'failed')])>Para continuar, rode no terminal do app no Coolify: <code class="font-mono">php artisan app:reset-system --continue</code></p>

            <a data-back href="{{ $back }}" @class(['text-sm font-medium text-accent-content underline', 'hidden' => $state['status'] === 'running'])>{{ $reset ? 'Voltar para o início' : 'Voltar para o Backup do banco' }}</a>
        </main>
    </body>
</html>
