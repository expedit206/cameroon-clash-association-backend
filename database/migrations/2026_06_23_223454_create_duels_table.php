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
        Schema::create('duels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('tournament_matches')->onDelete('cascade');
            $table->tinyInteger('hdv_level'); // 14, 15, 16, 17 ou 18
            $table->foreignId('player_home_id')->constrained('users');
            $table->foreignId('player_away_id')->constrained('users');
            $table->tinyInteger('stars_home')->nullable();
            $table->tinyInteger('stars_away')->nullable();
            $table->decimal('destruction_home', 5, 2)->nullable();
            $table->decimal('destruction_away', 5, 2)->nullable();
            $table->boolean('is_mvp_home')->default(false);
            $table->boolean('is_mvp_away')->default(false);
            $table->enum('winner', ['home', 'away', 'draw'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duels');
    }
};
