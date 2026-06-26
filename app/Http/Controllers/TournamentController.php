<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\ClanRegistration;
use App\Models\TournamentMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gère l'affichage public du tournoi (classement, bracket, résultats).
 */
class TournamentController extends Controller
{
    /**
     * Récupère le classement (Leaderboard) des clans inscrits.
     * Basé sur les statistiques enregistrées dans ClanRegistration.
     */
    public function getLeaderboard()
    {
        $leaderboard = ClanRegistration::with('clan')
            ->where('status', 'confirmed')
            ->orderByDesc('total_stars') // Priorité 1 : Étoiles
            ->orderByDesc('destruction_percentage') // Priorité 2 : %
            ->orderBy('created_at', 'asc') // Tie-break : Premier inscrit
            ->get();

        return response()->json($leaderboard);
    }

    /**
     * Récupère les données de l'arbre du tournoi (Bracket).
     */
    public function getBracket()
    {
        // On récupère tous les matches organisés par round
        // Rounds : 1 (8èmes), 2 (Quarts), 3 (Demis), 4 (Finale)
        $matches = TournamentMatch::with(['clan1', 'clan2'])
            ->orderBy('round')
            ->orderBy('match_number')
            ->get()
            ->groupBy('round');

        return response()->json($matches);
    }

    /**
     * Liste des 16 clans d'élite officiellement confirmés.
     */
    public function getClans()
    {
        $clans = ClanRegistration::with('clan')
            ->where('status', 'confirmed')
            ->get()
            ->map(function ($reg) {
                return [
                    'id' => $reg->clan->id,
                    'name' => $reg->clan->name,
                    'tag' => $reg->clan->tag_coc,
                    'badge' => $reg->clan->badge_url,
                    'level' => $reg->clan->clan_level,
                    'seed' => $reg->seed_number
                ];
            });

        return response()->json($clans);
    }
}
