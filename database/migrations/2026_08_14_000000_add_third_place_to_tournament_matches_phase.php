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
        // En MySQL, modifier un ENUM pour inclure 'third_place'
        DB::statement("ALTER TABLE tournament_matches MODIFY COLUMN phase ENUM('group_stage', 'semi_final', 'third_place', 'final') NOT NULL DEFAULT 'group_stage'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE tournament_matches MODIFY COLUMN phase ENUM('group_stage', 'semi_final', 'final') NOT NULL DEFAULT 'group_stage'");
    }
};
