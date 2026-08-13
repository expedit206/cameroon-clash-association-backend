<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mise à jour de la colonne ENUM `type` de wallet_transactions
     * pour inclure les nouveaux types du système P2P.
     * On alterne vers VARCHAR pour plus de flexibilité.
     */
    public function up(): void
    {
        // On change le type ENUM en VARCHAR pour éviter les contraintes ALTER TABLE ENUM
        DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN type VARCHAR(30) NOT NULL");
    }

    public function down(): void
    {
        // Retour à l'ENUM d'origine (conserve la compatibilité)
        DB::statement("ALTER TABLE wallet_transactions MODIFY COLUMN type ENUM(
            'deposit',
            'bet_place',
            'bet_win',
            'bet_refund',
            'withdrawal',
            'bet_lock',
            'bet_match',
            'bet_cancel',
            'bet_settlement',
            'commission'
        ) NOT NULL");
    }
};
