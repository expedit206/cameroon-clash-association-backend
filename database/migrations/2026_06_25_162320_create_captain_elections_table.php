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
        Schema::create('captain_elections', function (Blueprint $table) {
            $table->id();
            $table->string('clan_tag');
            $table->foreignId('competition_id')->constrained();
            $table->enum('status', ['open', 'closed', 'cancelled'])->default('open');
            $table->foreignId('winner_id')->nullable()->constrained('users');
            $table->timestamp('ends_at');
            $table->timestamps();
        });

        Schema::create('captain_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained('captain_elections')->onDelete('cascade');
            $table->foreignId('voter_id')->constrained('users');
            $table->foreignId('candidate_id')->constrained('users');
            $table->unique(['election_id', 'voter_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('captain_votes');
        Schema::dropIfExists('captain_elections');
    }
};
