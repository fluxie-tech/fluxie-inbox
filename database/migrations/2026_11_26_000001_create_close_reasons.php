<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Slug antigo de `close_outcome` => nome do motivo, na ordem de hoje. */
    private const REASONS = [
        'resolvido' => 'Resolvido',
        'sem_resposta' => 'Sem resposta',
        'cliente_pediu' => 'Cliente pediu',
    ];

    public function up(): void
    {
        Schema::create('close_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('requires_summary')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        DB::statement('create unique index close_reasons_name_lower_unique on close_reasons (lower(name))');

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('close_reason_id')->nullable()->constrained('close_reasons')->restrictOnDelete();
        });

        $ids = [];
        $position = 1;
        foreach (self::REASONS as $slug => $name) {
            $ids[$slug] = DB::table('close_reasons')->insertGetId([
                'name' => $name, 'position' => $position++, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('tickets')->where('close_outcome', $slug)->update(['close_reason_id' => $ids[$slug]]);
        }

        // `payload` é json (não jsonb): reescreve em PHP, mantendo `outcome`. Sem model nem updated_at (a tabela não os tem).
        DB::table('ticket_events')->where('type', 'closed')->chunkById(200, function ($events) use ($ids) {
            foreach ($events as $event) {
                $payload = is_string($event->payload) ? json_decode($event->payload, true) : null;
                $outcome = is_array($payload) ? ($payload['outcome'] ?? null) : null;
                if (is_string($outcome) && isset($ids[$outcome])) {
                    DB::table('ticket_events')->where('id', $event->id)
                        ->update(['payload' => json_encode($payload + ['close_reason_id' => $ids[$outcome]])]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('close_reason_id');
        });
        Schema::dropIfExists('close_reasons');
    }
};
