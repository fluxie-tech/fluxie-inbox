<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Feriados já cadastrados viram "cadastrado à mão" e continuam fechando o atendimento, como antes.
        Schema::table('holidays', function (Blueprint $table) {
            $table->string('kind', 20)->default('manual');
            $table->boolean('closes')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['kind', 'closes']);
        });
    }
};
