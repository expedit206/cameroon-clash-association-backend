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
        Schema::create('clan_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained('clans');
            $table->foreignId('competition_id')->constrained('competitions');
            $table->enum('status', ['pending_payment', 'paid', 'confirmed', 'disqualified'])->default('pending_payment');
            $table->tinyInteger('seed_number')->nullable(); // Numéro d'ordre tirage au sort
            $table->dateTime('paid_at')->nullable(); // Date de paiement reçu
            $table->foreignId('confirmed_by')->nullable()->constrained('users'); // Admin qui a confirmé
            $table->dateTime('confirmed_at')->nullable();
            $table->string('payment_reference', 100)->nullable(); // Référence MeSomb/NotchPay
            $table->timestamp('registered_at')->nullable(); // Date d'inscription complète
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clan_registrations');
    }
};
