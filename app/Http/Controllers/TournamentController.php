<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\ClanRegistration;
use App\Models\Competition;
use App\Models\TournamentMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gère l'affichage public du tournoi (classement, bracket, résultats).
 * Toutes les requêtes sont scopées sur la compétition active (Saison 2).
 */
class TournamentController extends Controller
{
    /**
     * Récupère la compétition active (la plus récente non terminée, ou la dernière saison).
     * Priorité : in_progress > open > draft > finished
     */
    private function currentCompetition(): ?Competition
    {
        return Competition::query()
            ->orderByRaw("FIELD(status, 'in_progress', 'open', 'draft', 'closed', 'finished')")
            ->orderByDesc('season_number')
            ->first();
    }

    /**
     * Récupère le classement (Leaderboard) des clans inscrits.
     */
    public function getLeaderboard()
    {
        $competition = $this->currentCompetition();

        if (!$competition) {
            return response()->json([]);
        }

        $leaderboard = ClanRegistration::with('clan')
            ->where('competition_id', $competition->id)
            ->where('status', 'confirmed')
            ->orderByDesc('total_stars')
            ->orderByDesc('destruction_percentage')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($leaderboard);
    }

    /**
     * Récupère les données de l'arbre du tournoi (Bracket).
     */
    public function getBracket()
    {
        $competition = $this->currentCompetition();

        if (!$competition) {
            return response()->json([
                'r16' => [],
                'r8'  => [],
                'r4'  => [],
                'r2'  => [],
            ]);
        }

        $matches = TournamentMatch::with(['clanHome', 'clanAway'])
            ->where('competition_id', $competition->id)
            ->orderBy('round')
            ->orderBy('match_number')
            ->get();

        $mapped = [
            'r16' => [],
            'r8'  => [],
            'r4'  => [],
            'r2'  => [],
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
     * Liste des clans officiellement confirmés pour la compétition active.
     */
    public function getClans()
    {
        $competition = $this->currentCompetition();

        if (!$competition) {
            return response()->json([]);
        }

        $clans = ClanRegistration::with('clan')
            ->where('competition_id', $competition->id)
            ->where('status', 'confirmed')
            ->orderBy('seed_number')
            ->get()
            ->map(function ($reg) {
                return [
                    'id'    => $reg->clan->id,
                    'name'  => $reg->clan->name,
                    'tag'   => $reg->clan->tag_coc,
                    'badge' => $reg->clan->badge_url,
                    'level' => $reg->clan->clan_level,
                    'seed'  => $reg->seed_number,
                ];
            });

        return response()->json($clans);
    }

    /**
     * Récupère la liste de tous les matchs de la compétition active.
     */
    public function getMatches()
    {
        $competition = $this->currentCompetition();

        if (!$competition) {
            return response()->json([]);
        }

        $matches = TournamentMatch::with(['clanHome', 'clanAway'])
            ->where('competition_id', $competition->id)
            ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_at', 'asc')
            ->orderBy('match_number', 'asc')
            ->get();

        return response()->json($matches);
    }

    /**
     * Récupère le classement des poules (si format groupes).
     * Pour la Saison 2 (élimination directe) cela retournera des tableaux vides.
     */
    public function getGroups(\App\Services\GroupStageService $service)
    {
        $competition = $this->currentCompetition();

        if (!$competition) {
            return response()->json(['A' => [], 'B' => []]);
        }

        return response()->json($service->getGroupStandings($competition->id));
    }

    /**
     * Retourne le champion de la compétition active (ou null).
     * Pour le palmarès Saison 1 on pourra appeler avec ?season=1 plus tard.
     */
    public function getChampion(Request $request)
    {
        $season = $request->query('season');

        $competition = $season
            ? Competition::where('season_number', $season)->first()
            : $this->currentCompetition();

        if (!$competition) {
            return response()->json(['champion' => null]);
        }

        $final = TournamentMatch::with(['winnerClan', 'clanHome', 'clanAway'])
            ->where('competition_id', $competition->id)
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
                'stars_winner'          => $isHome ? $final->total_stars_home : $final->total_stars_away,
                'stars_runner_up'       => $isHome ? $final->total_stars_away : $final->total_stars_home,
                'destruction_winner'    => $isHome ? $final->total_destruction_home : $final->total_destruction_away,
                'destruction_runner_up' => $isHome ? $final->total_destruction_away : $final->total_destruction_home,
            ],
            'season' => $competition->season_number,
        ]);
    }

    /**
     * Récapitulatif MVP & bilan de la phase de poules (si applicable).
     */
    public function getGroupStageSummary(\App\Services\GroupStageService $service)
    {
        $competition = $this->currentCompetition();

        if (!$competition) {
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

        $standings = $service->getGroupStandings($competition->id);
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

        $topAttack = collect($allClans)->sortByDesc('total_stars')->first();
        $topDestruction = collect($allClans)->sortByDesc('avg_destruction')->first();
        $undefeated = collect($allClans)
            ->filter(fn($c) => $c['played'] > 0 && $c['lost'] === 0)
            ->values()
            ->all();

        $qualifiedA = collect($standings['A'] ?? [])->take(2)->values()->all();
        $qualifiedB = collect($standings['B'] ?? [])->take(2)->values()->all();

        $groupMatches = TournamentMatch::where('competition_id', $competition->id)
            ->where(function ($q) {
                $q->where('phase', 'group_stage')->orWhereNotNull('group');
            })
            ->get();

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

    /**
     * Optionnel mais très utile : retourne les infos de la compétition active.
     * À appeler côté front au lieu de hardcoder les prix / dates.
     */
    public function getCompetition()
    {
        $competition = $this->currentCompetition();

        if (!$competition) {
            return response()->json(null, 404);
        }

        return response()->json([
            'id'                     => $competition->id,
            'name'                   => $competition->name,
            'slug'                   => $competition->slug,
            'season_number'          => $competition->season_number,
            'format'                 => $competition->format,
            'max_teams'              => $competition->max_teams,
            'registration_fee'       => $competition->registration_fee,
            'status'                 => $competition->status,
            'registration_opens_at'  => $competition->registration_opens_at,
            'registration_closes_at' => $competition->registration_closes_at,
            'starts_at'              => $competition->starts_at,
            'ends_at'                => $competition->ends_at,
            'prize_1st'              => $competition->prize_1st,
            'prize_2nd'              => $competition->prize_2nd,
            'prize_3rd'              => $competition->prize_3rd,
        ]);
    }
}