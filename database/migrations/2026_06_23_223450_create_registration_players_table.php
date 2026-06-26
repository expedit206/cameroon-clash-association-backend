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
        Schema::create('registration_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_registration_id')->constrained('clan_registrations')->onDelete('cascade');
            $table->foreignId('player_id')->constrained('users');
            $table->tinyInteger('hdv_position'); // 14, 15, 16, 17 ou 18
            $table->boolean('is_substitute')->default(false);
            $table->timestamp('verified_at')->nullable(); // Vérification API CoC OK
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_players');
    }
};
