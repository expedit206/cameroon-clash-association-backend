<?php

namespace App\Http\Controllers;

use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClanDiscoveryController extends Controller
{
    protected CocApiService $cocApi;

    public function __construct(CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Calcule le classement officiel des clans selon leurs performances dans la CCA National League.
     * Replaces the CoC location search with tournament-based standings.
     */
    public function searchCamerounClans(Request $request)
    {
        // 1. Récupérer tous les clans validés ou enregistrés dans la compétition
        $registrations = \App\Models\ClanRegistration::with('clan')
            ->where('competition_id', 1)
            ->get();

        $clansMap = [];

        // Inscrire les clans enregistrés au tournoi
        foreach ($registrations as $reg) {
            $clan = $reg->clan;
            if (!$clan) continue;

            $clansMap[$clan->id] = [
                'id'                 => $clan->id,
                'name'               => $clan->name,
                'tag'                => $clan->tag_coc,
                'tag_coc'            => $clan->tag_coc,
                'badge_url'          => $clan->badge_url,
                'badgeUrls'          => ['medium' => $clan->badge_url],
                'clanLevel'          => $clan->clan_level ?? 1,
                'members'            => 10,
                'group'              => $reg->group,
                'seed'               => $reg->seed_number,
                'registration_status' => $reg->status,
                'played'             => 0,
                'won'                => 0,
                'drawn'              => 0,
                'lost'               => 0,
                'points'             => 0,
                'total_stars'        => 0,
                'total_destruction'  => 0.0,
                'avg_destruction'    => 0.0,
            ];
        }

        // Compléter avec les autres clans de la plateforme si non enregistrés
        $allClans = \App\Models\Clan::where('status', 'validated')->get();
        foreach ($allClans as $clan) {
            if (!isset($clansMap[$clan->id])) {
                $clansMap[$clan->id] = [
                    'id'                 => $clan->id,
                    'name'               => $clan->name,
                    'tag'                => $clan->tag_coc,
                    'tag_coc'            => $clan->tag_coc,
                    'badge_url'          => $clan->badge_url,
                    'badgeUrls'          => ['medium' => $clan->badge_url],
                    'clanLevel'          => $clan->clan_level ?? 1,
                    'members'            => 10,
                    'group'              => null,
                    'seed'               => null,
                    'registration_status' => null,
                    'played'             => 0,
                    'won'                => 0,
                    'drawn'              => 0,
                    'lost'               => 0,
                    'points'             => 0,
                    'total_stars'        => 0,
                    'total_destruction'  => 0.0,
                    'avg_destruction'    => 0.0,
                ];
            }
        }

        // 2. Parcourir tous les matchs terminés du tournoi pour calculer le bilan
        $matches = \App\Models\TournamentMatch::where('competition_id', 1)
            ->where('status', 'completed')
            ->get();

        foreach ($matches as $match) {
            $homeId = $match->clan_home_id;
            $awayId = $match->clan_away_id;

            $homeStars = (int) ($match->total_stars_home ?? 0);
            $awayStars = (int) ($match->total_stars_away ?? 0);
            $homeDest  = (float) ($match->total_destruction_home ?? 0);
            $awayDest  = (float) ($match->total_destruction_away ?? 0);

            if (isset($clansMap[$homeId])) {
                $clansMap[$homeId]['played'] += 1;
                $clansMap[$homeId]['total_stars'] += $homeStars;
                $clansMap[$homeId]['total_destruction'] += $homeDest;
            }

            if (isset($clansMap[$awayId])) {
                $clansMap[$awayId]['played'] += 1;
                $clansMap[$awayId]['total_stars'] += $awayStars;
                $clansMap[$awayId]['total_destruction'] += $awayDest;
            }

            if ($homeStars > $awayStars) {
                if (isset($clansMap[$homeId])) { $clansMap[$homeId]['won'] += 1; $clansMap[$homeId]['points'] += 3; }
                if (isset($clansMap[$awayId])) { $clansMap[$awayId]['lost'] += 1; }
            } elseif ($awayStars > $homeStars) {
                if (isset($clansMap[$awayId])) { $clansMap[$awayId]['won'] += 1; $clansMap[$awayId]['points'] += 3; }
                if (isset($clansMap[$homeId])) { $clansMap[$homeId]['lost'] += 1; }
            } else {
                if ($homeDest > $awayDest) {
                    if (isset($clansMap[$homeId])) { $clansMap[$homeId]['won'] += 1; $clansMap[$homeId]['points'] += 3; }
                    if (isset($clansMap[$awayId])) { $clansMap[$awayId]['lost'] += 1; }
                } elseif ($awayDest > $homeDest) {
                    if (isset($clansMap[$awayId])) { $clansMap[$awayId]['won'] += 1; $clansMap[$awayId]['points'] += 3; }
                    if (isset($clansMap[$homeId])) { $clansMap[$homeId]['lost'] += 1; }
                } else {
                    if (isset($clansMap[$homeId])) { $clansMap[$homeId]['drawn'] += 1; $clansMap[$homeId]['points'] += 1; }
                    if (isset($clansMap[$awayId])) { $clansMap[$awayId]['drawn'] += 1; $clansMap[$awayId]['points'] += 1; }
                }
            }
        }

        // 3. Calcul de la destruction moyenne par match
        foreach ($clansMap as &$c) {
            $c['avg_destruction'] = $c['played'] > 0 ? round($c['total_destruction'] / $c['played'], 2) : 0.0;
            $c['clanPoints']      = $c['points']; // compatibilité frontend
        }
        unset($c);

        // 4. Tri par mérite du tournoi
        $standings = array_values($clansMap);
        usort($standings, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['total_stars'] !== $b['total_stars']) return $b['total_stars'] <=> $a['total_stars'];
            if ($a['avg_destruction'] !== $b['avg_destruction']) return $b['avg_destruction'] <=> $a['avg_destruction'];
            if ($a['won'] !== $b['won']) return $b['won'] <=> $a['won'];
            return strcmp($a['name'], $b['name']);
        });

        // Assigner les rangs
        foreach ($standings as $idx => &$c) {
            $c['rank'] = $idx + 1;
        }
        unset($c);

        // 5. Filtres (recherche par nom / filtre par groupe)
        if ($request->filled('name')) {
            $searchTerm = mb_strtolower(trim($request->query('name')));
            $standings = array_values(array_filter($standings, function ($c) use ($searchTerm) {
                return str_contains(mb_strtolower($c['name']), $searchTerm) || str_contains(mb_strtolower($c['tag']), $searchTerm);
            }));
        }

        if ($request->filled('group')) {
            $groupTerm = strtoupper(trim($request->query('group')));
            $standings = array_values(array_filter($standings, function ($c) use ($groupTerm) {
                return $c['group'] === $groupTerm;
            }));
        }

        return response()->json([
            'items' => $standings,
            'total' => count($standings)
        ]);
    }

}
