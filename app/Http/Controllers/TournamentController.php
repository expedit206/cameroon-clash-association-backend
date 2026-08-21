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

    /**
     * Récupère le récapitulatif MVP & bilan global de la phase de poules.
     */
    /**
     * Retourne le clan champion de la Saison 1 (vainqueur de la Grande Finale).
     * Retourne null si la finale n'a pas encore de vainqueur.
     */
    public function getChampion()
    {
        $final = TournamentMatch::with(['winnerClan', 'clanHome', 'clanAway'])
            ->where('phase', 'final')
            ->where('status', 'completed')
            ->whereNotNull('winner_clan_id')
            ->first();

        if (!$final || !$final->winnerClan) {
            return response()->json(['champion' => null]);
        }

        $champion = $final->winnerClan;
        $isHome = $final->winner_clan_id === $final->clan_home_id;

        return response()->json([
            'champion' => [
                'id'          => $champion->id,
                'name'        => $champion->name,
                'tag'         => $champion->tag_coc,
                'badge_url'   => $champion->badge_url,
                'clan_level'  => $champion->clan_level,
                'stars'       => $isHome ? $final->total_stars_home : $final->total_stars_away,
                'destruction' => $isHome ? $final->total_destruction_home : $final->total_destruction_away,
            ],
            'runner_up' => [
                'id'        => $isHome ? $final->clanAway?->id : $final->clanHome?->id,
                'name'      => $isHome ? $final->clanAway?->name : $final->clanHome?->name,
                'badge_url' => $isHome ? $final->clanAway?->badge_url : $final->clanHome?->badge_url,
                'stars'     => $isHome ? $final->total_stars_away : $final->total_stars_home,
            ],
            'match' => [
                'stars_winner'    => $isHome ? $final->total_stars_home : $final->total_stars_away,
                'stars_runner_up' => $isHome ? $final->total_stars_away : $final->total_stars_home,
                'destruction_winner'    => $isHome ? $final->total_destruction_home : $final->total_destruction_away,
                'destruction_runner_up' => $isHome ? $final->total_destruction_away : $final->total_destruction_home,
            ],
        ]);
    }

    /**
     * Récupère le récapitulatif MVP & bilan global de la phase de poules.
     */
    public function getGroupStageSummary(\App\Services\GroupStageService $service)
    {
        $standings = $service->getGroupStandings(1);
        $allClans = array_merge($standings['A'] ?? [], $standings['B'] ?? []);

        if (empty($allClans)) {
            return response()->json([
                'top_attack' => null,
                'top_destruction' => null,
                'undefeated_clans' => [],
                'qualified_clans' => [],
                'total_group_matches' => 0,
                'completed_group_matches' => 0,
                'is_completed' => false,
            ]);
        }

        // Top Attaque (Le clan avec le plus d'étoiles)
        $topAttack = collect($allClans)->sortByDesc('total_stars')->first();

        // Top Destruction (% moyen de destruction le plus élevé)
        $topDestruction = collect($allClans)->sortByDesc('avg_destruction')->first();

        // Clans Invaincus (0 défaite)
        $undefeated = collect($allClans)->filter(fn($c) => $c['played'] > 0 && $c['lost'] === 0)->values()->all();

        // Les 4 qualifiés (Top 2 Groupe A & Top 2 Groupe B)
        $qualifiedA = collect($standings['A'] ?? [])->take(2)->values()->all();
        $qualifiedB = collect($standings['B'] ?? [])->take(2)->values()->all();

        // Statistiques des matchs de poule
        $groupMatches = TournamentMatch::where('competition_id', 1)
            ->where(function($q) {
                $q->where('phase', 'group_stage')->orWhereNotNull('group');
            })->get();

        $totalMatches = $groupMatches->count();
        $completedMatches = $groupMatches->where('status', 'completed')->count();

        return response()->json([
            'top_attack' => $topAttack,
            'top_destruction' => $topDestruction,
            'undefeated_clans' => $undefeated,
            'qualified_clans' => [
                'group_A' => $qualifiedA,
                'group_B' => $qualifiedB,
            ],
            'total_group_matches' => $totalMatches,
            'completed_group_matches' => $completedMatches,
            'is_completed' => $totalMatches > 0 && $totalMatches === $completedMatches,
        ]);
    }
}
