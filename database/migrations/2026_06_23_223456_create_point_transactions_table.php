<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Points pour les clans
        Schema::create('clan_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained('clans')->onDelete('cascade');
            $table->foreignId('competition_id')->constrained('competitions');
            $table->tinyInteger('points'); // Points gagnés
            $table->enum('reason', ['participation', 'win_r16', 'win_quarter', 'win_semi', 'win_final']);
            $table->foreignId('match_id')->nullable()->constrained('tournament_matches');
            $table->timestamps(); // includes created_at
        });

        // Points pour les joueurs
        Schema::create('player_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('duel_id')->constrained('duels')->onDelete('cascade');
            $table->foreignId('competition_id')->constrained('competitions');
            $table->tinyInteger('points'); // Points gagnés
            $table->enum('reason', ['win_duel', 'three_stars_bonus', 'mvp_bonus']);
            $table->timestamps(); // includes created_at
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_point_transactions');
        Schema::dropIfExists('clan_point_transactions');
    }
};
