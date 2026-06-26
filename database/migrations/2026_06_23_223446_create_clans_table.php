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
        Schema::create('clans', function (Blueprint $table) {
            $table->id();
            $table->string('tag_coc', 20)->unique(); // Tag clan CoC (#XXXXX)
            $table->string('name', 100); // Nom du clan
            $table->foreignId('captain_id')->constrained('users'); // Capitaine du clan
            $table->string('badge_url')->nullable(); // URL logo clan (API CoC)
            $table->tinyInteger('clan_level')->nullable(); // Niveau du clan CoC
            $table->enum('status', ['pending', 'validated', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable(); // Motif de refus si applicable
            $table->integer('total_points')->default(0); // Points historiques cumulés
            $table->integer('seasons_played')->default(0); // Nombre de saisons jouées
            $table->integer('titles_won')->default(0); // Nombre de titres
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clans');
    }
};
