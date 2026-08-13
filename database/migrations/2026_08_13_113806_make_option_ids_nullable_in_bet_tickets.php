<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rendre creator_option_id et taker_option_id nullables.
     * Le système V2 utilise le champ `side` (YES/NO) à la place des options legacy.
     */
    public function up(): void
    {
        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('creator_option_id')->nullable()->change();
            $table->unsignedBigInteger('taker_option_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('creator_option_id')->nullable(false)->change();
            $table->unsignedBigInteger('taker_option_id')->nullable(false)->change();
        });
    }
};
