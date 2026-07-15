<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration d'adaptation pour la table tournament_matches.
 * Permet de stocker les détails de match/guerre importés en temps réel depuis les logs de guerre officiels CoC
 * et nettoie la colonne obsolète result_screenshot_url.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            // Nombre de joueurs par équipe dans la guerre (ex: 5, 10, 15)
            $table->tinyInteger('team_size')->nullable()->after('scheduled_at');
            
            // Modificateur de combat de la guerre (ex: "none", "hardMode")
            $table->string('battle_modifier')->nullable()->after('team_size');
            
            // Date de fin effective de la guerre telle que fournie par l'API Clash of Clans (endTime)
            $table->dateTime('war_end_time')->nullable()->after('battle_modifier');
            
            // Nombre d'attaques réalisées par le clan hôte (home)
            $table->tinyInteger('attacks_home')->nullable()->after('total_stars_home');
            
            // Nombre d'attaques réalisées par le clan invité (away)
            $table->tinyInteger('attacks_away')->nullable()->after('total_stars_away');

            // Suppression du screenshot devenu inutile car tout est automatisé via l'API officiel
            $table->dropColumn('result_screenshot_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn([
                'team_size',
                'battle_modifier',
                'war_end_time',
                'attacks_home',
                'attacks_away'
            ]);

            $table->string('result_screenshot_url')->nullable()->after('total_destruction_away');
        });
    }
};
