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
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150); // Ex: "CCA National League"
            $table->string('slug', 150)->unique(); // URL-friendly
            $table->integer('season_number'); // 1, 2, 3…
            $table->enum('format', ['elimination_directe', 'groupes'])->default('elimination_directe');
            $table->tinyInteger('max_teams')->default(16); // Nombre max d'équipes
            $table->integer('registration_fee')->default(5000); // Frais en FCFA
            $table->enum('status', ['draft', 'open', 'closed', 'in_progress', 'finished'])->default('draft');
            $table->dateTime('registration_opens_at');
            $table->dateTime('registration_closes_at');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->integer('prize_1st')->default(30000); // Cash prize 1er (Updated by user request)
            $table->integer('prize_2nd')->default(20000); // Cash prize 2ème
            $table->integer('prize_3rd')->default(0); // Cash prize 3ème
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
