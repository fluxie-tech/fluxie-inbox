<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `auto_reply`: resposta automática (fora do horário), que não conta como "a conversa é nossa" no encerramento automático.
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('auto_reply')->default(false);
        });

        // Quando o cliente foi avisado de que o atendimento será encerrado por falta de resposta.
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('auto_close_warned_at')->nullable();
        });

        // A resposta fora do horário que já existe: a primeira mensagem `bot` do ticket gravada a partir do evento. A Automação
        // também usa a origem `bot`, então a origem sozinha não basta. O que identifica é o evento `after_hours_reply`: único por
        // ticket (`AfterHoursReply::eligible`), com a mensagem criada logo depois dele, na mesma transação. Só canal sem fluxo
        // de Automação recebe essa resposta, então a primeira `bot` depois do evento é a dele.
        DB::statement(<<<'SQL'
            UPDATE messages SET auto_reply = true WHERE id IN (
                SELECT MIN(m.id) FROM messages m
                JOIN ticket_events e ON e.ticket_id = m.ticket_id AND e.type = 'after_hours_reply'
                WHERE m.origin = 'bot' AND m.created_at >= e.created_at
                GROUP BY e.ticket_id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('auto_close_warned_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('auto_reply');
        });
    }
};
