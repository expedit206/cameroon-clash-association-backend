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
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('tournament_matches')->onDelete('cascade');
            $table->foreignId('submitted_by')->constrained('users'); // Capitaine demandeur
            $table->foreignId('clan_id')->constrained('clans'); // Clan qui porte plainte
            $table->text('description');
            $table->json('evidence_urls')->nullable(); // URLs des preuves (screenshots)
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users'); // Arbitre
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // created_at + 24h
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
