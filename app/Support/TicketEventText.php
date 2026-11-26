<?php

namespace App\Support;

use App\Models\TicketEvent;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/** Como um evento do ticket aparece em texto: o rótulo do tipo e o detalhe. Usado na auditoria e no histórico. */
final class TicketEventText
{
    public const TYPES = [
        'opened' => 'Ticket aberto',
        'claimed' => 'Assumido',
        'closed' => 'Encerrado',
        'transferred' => 'Transferido',
        'reassigned' => 'Reatribuído',
        'taken_over' => 'Tomado',
        'merged' => 'Mesclado',
        'message_out' => 'Mensagem enviada',
        'message_in' => 'Mensagem recebida',
        'after_hours_reply' => 'Resposta fora do horário',
        'media_purged' => 'Mídia apagada',
        'sla_breached' => 'SLA estourado',
        'message_reacted' => 'Reação a mensagem',
        'message_edited' => 'Mensagem editada',
        'message_revoked_by_agent' => 'Mensagem apagada para todos',
        'note_deleted' => 'Nota interna apagada',
        'note_restored' => 'Nota interna restaurada',
        'tag_added' => 'Etiqueta adicionada',
        'tag_removed' => 'Etiqueta removida',
        'opened_by_agent' => 'Conversa iniciada pela equipe',
        'bot_started' => 'Automação iniciou o atendimento',
        'bot_abandoned' => 'Equipe respondeu pelo celular; a triagem da Automação acabou',
        'bot_handoff' => 'Automação encaminhou',
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    /** O que aconteceu, em texto: a espera do SLA; os demais não têm detalhe aqui (o encerramento é da TicketTimeline). */
    public static function detail(TicketEvent $event): string
    {
        $payload = $event->payload ?? [];

        if ($event->type === 'sla_breached') {
            return self::slaDetail($payload);
        }

        // O payload dos demais tipos é dado interno: nunca vira texto na tela.
        return '';
    }

    /**
     * "esperando desde 30/10 10:00, limite 30/10 10:10" no fuso de exibição; vazio se o payload não traz as duas datas válidas.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function slaDetail(array $payload): string
    {
        $since = self::localTime($payload['waiting_since'] ?? null);
        $due = self::localTime($payload['due_at'] ?? null);

        return $since === null || $due === null ? '' : "esperando desde {$since}, limite {$due}";
    }

    private static function localTime(mixed $iso): ?string
    {
        if (! is_string($iso)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($iso)->setTimezone(config()->string('app.display_timezone'))->format('d/m H:i');
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
