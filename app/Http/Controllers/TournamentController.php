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
        $matches = TournamentMatch::with(['clanHome', 'clanAway'])
            ->orderBy('round')
            ->orderBy('match_number')
            ->get();

        $mapped = [
            'r16' => [],
            'r8' => [],
            'r4' => [],
            'r2' => []
        ];

        foreach ($matches as $match) {
            $roundKey = match ((int) $match->round) {
                1 => 'r16',
                2 => 'r8',
                3 => 'r4',
                4 => 'r2',
                default => 'r16',
            };
            $mapped[$roundKey][] = $match;
        }

        return response()->json($mapped);
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

    /**
     * Récupère la liste de tous les matchs du tournoi (poules + phase finale).
     */
    public function getMatches()
    {
        $matches = TournamentMatch::with(['clanHome', 'clanAway'])
            ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_at', 'asc')
            ->orderBy('match_number', 'asc')
            ->get();

        return response()->json($matches);
    }

    /**
     * Récupère le classement général des équipes.
     */
    public function getGroups(\App\Services\GroupStageService $service)
    {
        return response()->json($service->getGroupStandings(1));
    }
}
