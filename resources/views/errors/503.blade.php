@extends('errors.layout')

{{-- Sem consulta ao banco: durante uma restauração ele está sendo trocado. A CSP bloqueia script inline, então a recarga é por meta. --}}
@section('head')
    <meta http-equiv="refresh" content="10">
@endsection
@section('sem_botao', '1')
@if (app(\App\Services\Backup\Restore\RestoreProgress::class)->isRunning())
    @section('title', 'Restauração do banco em andamento')
    @section('message', 'O sistema volta em instantes. Esta página recarrega sozinha.')
@else
    @section('title', 'Sistema em manutenção')
    @section('message', 'Volte em instantes. Esta página recarrega sozinha.')
@endif
