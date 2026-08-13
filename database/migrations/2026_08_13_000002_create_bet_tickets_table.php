<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_tickets', function (Blueprint $table) {
            $table->id();

            // Numéro lisible unique (ex: TCK-1042)
            $table->string('ticket_number')->unique();

            // Marché et options
            $table->foreignId('market_id')->constrained('bet_markets')->cascadeOnDelete();
            $table->foreignId('creator_option_id')->constrained('bet_options');

            // Utilisateurs
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('taker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('taker_option_id')->nullable()->constrained('bet_options')->nullOnDelete();

            // Financier (immutable une fois matched)
            $table->unsignedBigInteger('amount');               // Mise individuelle (FCFA)
            $table->decimal('odds', 4, 2)->default(2.00);       // Cote fixe P2P
            $table->unsignedBigInteger('gross_payout');          // amount * 2
            $table->decimal('commission_percentage', 5, 2);     // % commission figée à la création
            $table->unsignedBigInteger('commission_amount');     // gross_payout * commission_percentage / 100
            $table->unsignedBigInteger('net_payout');            // gross_payout - commission_amount

            // Machine à états
            $table->enum('status', [
                'open',       // En attente d'un preneur
                'matched',    // Apparié — Les deux mises bloquées
                'locked',     // Marché fermé (match imminent)
                'settled',    // Réglé — Gagnant crédité
                'cancelled',  // Annulé par le créateur (OPEN uniquement)
                'refunded',   // Remboursé suite à annulation admin du marché
            ])->default('open');

            // Résultat
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();

            // Antifraude
            $table->unsignedInteger('risk_score')->default(0);
            $table->boolean('review_required')->default(false);

            // Horodatages métier
            $table->timestamp('expires_at')->nullable();     // Expiration automatique si non matché
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // Index
            $table->index(['market_id', 'status']);
            $table->index(['creator_id', 'status']);
            $table->index(['taker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_tickets');
    }
};
