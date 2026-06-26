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
        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained('competitions');
            $table->enum('round', ['r16', 'quarter', 'semi', 'third_place', 'final']);
            $table->tinyInteger('match_number');
            $table->foreignId('clan_home_id')->constrained('clans');// Clan qui déclare la guerre
            $table->foreignId('clan_away_id')->constrained('clans');
            $table->dateTime('scheduled_at')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'pending_validation', 'validated', 'forfeit'])->default('scheduled');
            $table->foreignId('winner_clan_id')->nullable()->constrained('clans');
            $table->foreignId('forfeit_clan_id')->nullable()->constrained('clans');
            $table->tinyInteger('total_stars_home')->nullable();
            $table->tinyInteger('total_stars_away')->nullable();
            $table->decimal('total_destruction_home', 5, 2)->nullable();
            $table->decimal('total_destruction_away', 5, 2)->nullable();
            $table->string('result_screenshot_url')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users'); // Arbitre
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
