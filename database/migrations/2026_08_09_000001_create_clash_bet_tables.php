<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. CCA Wallets ───────────────────────────────────────────────
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);          // FCFA disponible
            $table->unsignedBigInteger('locked_amount')->default(0);    // Mis en jeu (paris actifs)
            $table->unsignedBigInteger('total_deposited')->default(0);
            $table->unsignedBigInteger('total_won')->default(0);
            $table->unsignedBigInteger('total_withdrawn')->default(0);
            $table->timestamps();
        });

        // ─── 2. Wallet Transactions (journal immuable) ────────────────────
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->enum('type', [
                'deposit',       // Rechargement via NotchPay
                'bet_place',     // Débit lors d'un pari
                'bet_win',       // Crédit gain
                'bet_refund',    // Remboursement (marché annulé / égalité)
                'withdrawal',    // Retrait Mobile Money
            ]);
            $table->unsignedBigInteger('amount');         // Montant brut du mouvement
            $table->unsignedBigInteger('balance_before'); // Solde AVANT
            $table->unsignedBigInteger('balance_after');  // Solde APRÈS
            $table->string('reference')->nullable();      // Ex: notchpay ref, bet_id, withdrawal_id
            $table->string('reference_type')->nullable(); // Ex: 'App\Models\Bet'
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'type']);
            $table->index('reference');
        });

        // ─── 3. Marchés de prédiction ─────────────────────────────────────
        Schema::create('bet_markets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('tournament_matches')->cascadeOnDelete();
            $table->enum('status', ['open', 'suspended', 'closed', 'settled', 'cancelled'])
                  ->default('open');
            $table->unsignedBigInteger('liquidity_weight')->default(100000); // Bonding curve buffer (FCFA)
            $table->unsignedBigInteger('total_pool')->default(0);            // Somme totale des mises
            $table->string('cancelled_reason')->nullable();
            $table->timestamp('betting_closes_at')->nullable();  // Fermeture auto des paris
            $table->timestamps();

            $table->index(['match_id', 'status']);
        });

        // ─── 4. Options de marché (Équipe A / Équipe B) ───────────────────
        Schema::create('bet_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('bet_markets')->cascadeOnDelete();
            $table->string('label');                             // Ex: "Les Victoires"
            $table->foreignId('clan_id')->nullable()->constrained('clans')->nullOnDelete();
            $table->unsignedBigInteger('current_pool')->default(0);      // Montant total misé sur cette option
            $table->unsignedBigInteger('reserved_payout')->default(0);  // Gains max réservés (solvabilité)
            $table->timestamps();

            $table->index('market_id');
        });

        // ─── 5. Paris placés par les utilisateurs ─────────────────────────
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('bet_markets')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('bet_options')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');               // Mise placée (FCFA)
            $table->decimal('executed_odds', 6, 2);             // Cote figée à l'acceptation
            $table->unsignedBigInteger('potential_payout');     // Gain potentiel brut (amount × executed_odds)
            $table->enum('status', ['pending', 'won', 'lost', 'refunded'])->default('pending');
            $table->unsignedBigInteger('actual_payout')->nullable(); // Gain réel crédité
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['market_id', 'option_id']);
        });

        // ─── 6. Demandes de retrait ───────────────────────────────────────
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');           // Montant demandé
            $table->unsignedBigInteger('fee');              // Frais 7%
            $table->unsignedBigInteger('net_amount');       // Montant net versé (amount - fee)
            $table->string('phone_number');
            $table->enum('payment_method', ['mtn_momo', 'orange_money']);
            $table->string('notchpay_reference')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('admin_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('bets');
        Schema::dropIfExists('bet_options');
        Schema::dropIfExists('bet_markets');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
