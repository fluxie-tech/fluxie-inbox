<!DOCTYPE html>
{{-- Sem `partials.head`, `CompanySettings`, `@auth` ou banco: a página de erro 500 tem de renderizar com o banco fora do ar. --}}
<html lang="pt-BR">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        @yield('head')
        <title>@yield('title') - {{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @fonts
        @fluxAppearance
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-8 p-6">
            <span class="text-sm font-semibold tracking-tight">{{ config('app.name') }}</span>

            <div class="w-full max-w-[400px] rounded-xl border border-line bg-surface p-7 shadow-xs">
                <div class="flex flex-col gap-3">
                    @hasSection('code')
                        <p class="text-sm font-medium text-ink-3">@yield('code')</p>
                    @endif
                    <h1 class="text-lg font-semibold">@yield('title')</h1>
                    <p class="text-sm text-ink-2">@yield('message')</p>
                    @unless (View::hasSection('sem_botao'))
                        <flux:button variant="primary" href="{{ route('home') }}" class="mt-2 w-full">Voltar ao início</flux:button>
                    @endunless
                </div>
            </div>
        </div>
    </body>
</html>
