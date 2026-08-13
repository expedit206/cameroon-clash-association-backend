<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Seed default Clash Bet configuration
        DB::table('app_settings')->insert([
            [
                'key'         => 'clash_bet_commission_percentage',
                'value'       => '10',
                'description' => 'Commission prélevée sur le gain brut lors du règlement (en %)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'clash_bet_close_offset_minutes',
                'value'       => '5',
                'description' => 'Fermeture automatique des paris N minutes avant le match',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'clash_bet_min_amount',
                'value'       => '100',
                'description' => 'Mise minimale par ticket (FCFA)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'clash_bet_max_amount',
                'value'       => '50000',
                'description' => 'Mise maximale par ticket (FCFA)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'clash_bet_fixed_odds',
                'value'       => '2.00',
                'description' => 'Cote fixe P2P pour les marchés binaires',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'clash_bet_withdrawal_fee_percentage',
                'value'       => '10',
                'description' => 'Frais de retrait Mobile Money (en %)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
