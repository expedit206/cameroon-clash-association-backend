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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_registration_id')->constrained('clan_registrations')->onDelete('cascade');
            $table->integer('amount')->default(5000);
            $table->string('currency', 3)->default('XAF');
            $table->string('reference', 100)->unique(); // Référence MeSomb/NotchPay
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('pending');
            $table->foreignId('confirmed_by')->nullable()->constrained('users'); // Admin qui a confirmé
            $table->dateTime('confirmed_at')->nullable();
            $table->enum('payment_method', ['mtn_momo', 'orange_money'])->nullable();
            $table->string('phone_number')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
