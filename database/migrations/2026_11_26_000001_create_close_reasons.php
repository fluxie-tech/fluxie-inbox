<?php

use App\Models\TicketEvent;
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
        $position = 0;
        foreach (self::REASONS as $slug => $name) {
            $ids[$slug] = DB::table('close_reasons')->insertGetId([
                'name' => $name, 'position' => $position++, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('tickets')->where('close_outcome', $slug)->update(['close_reason_id' => $ids[$slug]]);
        }

        // `payload` é json (não jsonb): reescreve em PHP, mantendo `outcome`.
        TicketEvent::query()->where('type', 'closed')->chunkById(200, function ($events) use ($ids) {
            foreach ($events as $event) {
                $payload = $event->payload ?? [];
                $outcome = $payload['outcome'] ?? null;
                if (is_string($outcome) && isset($ids[$outcome])) {
                    $event->update(['payload' => $payload + ['close_reason_id' => $ids[$outcome]]]);
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
