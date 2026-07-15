<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Convertit le champ 'round' de ENUM string vers TINYINT.
     * Valeurs: 1=8ème, 2=Quart, 3=Demi, 4=Finale
     */
    public function up(): void
    {
        // Sur MySQL, on ne peut pas changer directement un enum en integer.
        // On drop la colonne et la recrée avec le bon type.
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn('round');
        });

        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->tinyInteger('round')->default(1)->after('competition_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn('round');
        });

        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->enum('round', ['r16', 'quarter', 'semi', 'third_place', 'final'])->after('competition_id');
        });
    }
};
