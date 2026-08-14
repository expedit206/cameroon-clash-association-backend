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
        Schema::table('bet_markets', function (Blueprint $table) {
            $table->boolean('allow_during_match')->default(false)->after('betting_closes_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bet_markets', function (Blueprint $table) {
            $table->dropColumn('allow_during_match');
        });
    }
};
