<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ajouter temporairement 'completed' aux valeurs de l'enum pour pouvoir faire la mise à jour sans violation de contraintes.
        DB::statement("ALTER TABLE tournament_matches MODIFY COLUMN status ENUM('scheduled', 'in_progress', 'pending_validation', 'validated', 'completed', 'forfeit') NOT NULL DEFAULT 'scheduled'");

        // 2. Mettre à jour les données existantes de 'validated' vers 'completed'.
        DB::table('tournament_matches')
            ->where('status', 'validated')
            ->update(['status' => 'completed']);

        // 3. Modifier définitivement la contrainte ENUM en supprimant 'validated' et 'pending_validation'.
        DB::statement("ALTER TABLE tournament_matches MODIFY COLUMN status ENUM('scheduled', 'in_progress', 'completed', 'forfeit') NOT NULL DEFAULT 'scheduled'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE tournament_matches MODIFY COLUMN status ENUM('scheduled', 'in_progress', 'pending_validation', 'validated', 'completed', 'forfeit') NOT NULL DEFAULT 'scheduled'");

        DB::table('tournament_matches')
            ->where('status', 'completed')
            ->update(['status' => 'validated']);

        DB::statement("ALTER TABLE tournament_matches MODIFY COLUMN status ENUM('scheduled', 'in_progress', 'pending_validation', 'validated', 'forfeit') NOT NULL DEFAULT 'scheduled'");
    }
};
