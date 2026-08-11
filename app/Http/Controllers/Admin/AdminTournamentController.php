<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\BracketGeneratorService;
use Illuminate\Http\Request;

/**
 * Gestion du tournoi (génération, rounds) par les admins.
 */
class AdminTournamentController extends Controller
{
    public function matches(Competition $competition)
    {
        $matches = \App\Models\TournamentMatch::where('competition_id', $competition->id)
            ->with(['clanHome', 'clanAway', 'duels'])
            ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_at', 'asc')
            ->orderBy('match_number', 'asc')
            ->get();

        return response()->json($matches);
    }

    public function generateBracket(Request $request, Competition $competition, BracketGeneratorService $service)
    {
        try {
            $service->generateInitialBracket($competition);
            return response()->json([
                'message' => "Le tableau du tournoi a été généré avec succès ! Le combat commence."
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function updateMatch(Request $request, \App\Models\TournamentMatch $match)
    {
        $request->validate([
            'total_stars_home' => 'nullable|integer|min:0|max:15',
            'total_stars_away' => 'nullable|integer|min:0|max:15',
            'total_destruction_home' => 'nullable|numeric|min:0|max:100',
            'total_destruction_away' => 'nullable|numeric|min:0|max:100',
            'status' => 'nullable|string|in:scheduled,in_progress,completed,forfeit',
            'scheduled_at' => 'nullable|date',
        ]);

        $match->update($request->only([
            'total_stars_home',
            'total_stars_away',
            'total_destruction_home',
            'total_destruction_away',
            'status',
            'scheduled_at',
            'round',
            'match_number',
        ]));

        // Logique auto pour le vainqueur si complété
        if ($match->status === 'completed') {
            if ($match->total_stars_home > $match->total_stars_away) {
                $match->winner_clan_id = $match->clan_home_id;
            } elseif ($match->total_stars_away > $match->total_stars_home) {
                $match->winner_clan_id = $match->clan_away_id;
            } else {
                // Egalité aux étoiles, on regarde le %
                if ($match->total_destruction_home > $match->total_destruction_away) {
                    $match->winner_clan_id = $match->clan_home_id;
                } else {
                    $match->winner_clan_id = $match->clan_away_id;
                }
            }
            $match->validated_by = $request->user()->id;
            $match->validated_at = now();
            $match->save();

            // Tenter de faire progresser le tournoi si c'est un match de tableau à élimination (round)
            if ($match->round && $match->phase !== 'group_stage') {
                $service = new \App\Services\BracketGeneratorService();
                $service->advanceTournament($match->competition, $match->round);
            }
        }

        return response()->json(['message' => 'Match mis à jour avec succès.', 'match' => $match->load(['clanHome', 'clanAway'])]);
    }

    public function confirmedClans(Competition $competition)
    {
        $registrations = \App\Models\ClanRegistration::with('clan')
            ->where('competition_id', $competition->id)
            ->where('status', 'confirmed')
            ->orderBy('updated_at', 'asc')
            ->get();

        return response()->json($registrations);
    }

    public function assignGroup(Request $request, Competition $competition)
    {
        $request->validate([
            'clan_id' => 'required|exists:clans,id',
            'group' => 'nullable|string|in:A,B',
        ]);

        $registration = \App\Models\ClanRegistration::where('competition_id', $competition->id)
            ->where('clan_id', $request->clan_id)
            ->firstOrFail();

        $registration->group = $request->group;
        $registration->updated_at = now();
        $registration->save();

        return response()->json([
            'message' => 'Clan assigné au groupe avec succès.',
            'registration' => $registration->load('clan')
        ]);
    }

    public function createMatch(Request $request, Competition $competition)
    {
        $request->validate([
            'clan_home_id' => 'required|exists:clans,id',
            'clan_away_id' => 'required|exists:clans,id|different:clan_home_id',
            'phase' => 'required|string|in:group_stage,semi_final,final',
            'group' => 'nullable|string|in:A,B',
            'round' => 'nullable|integer',
            'match_number' => 'nullable|integer',
        ]);

        $nextMatchNum = $request->match_number ?? (\App\Models\TournamentMatch::where('competition_id', $competition->id)->max('match_number') + 1);

        $match = \App\Models\TournamentMatch::create([
            'competition_id' => $competition->id,
            'round' => $request->round ?? 1,
            'group' => $request->group,
            'phase' => $request->phase,
            'match_number' => $nextMatchNum,
            'clan_home_id' => $request->clan_home_id,
            'clan_away_id' => $request->clan_away_id,
            'status' => 'scheduled',
        ]);

        return response()->json([
            'message' => 'Match créé avec succès.',
            'match' => $match->load(['clanHome', 'clanAway'])
        ]);
    }

    public function deleteMatch(\App\Models\TournamentMatch $match)
    {
        $match->delete();
        return response()->json(['message' => 'Match supprimé.']);
    }

    public function groupStandings(Competition $competition, \App\Services\GroupStageService $service)
    {
        return response()->json($service->getGroupStandings($competition->id));
    }

    /**
     * Génère automatiquement tous les matchs de poule pour les deux groupes selon le règlement officiel CCA :
     * Chaque clan dispute exactement 4 matchs dans sa poule.
     * - Groupe A (6 clans) : 4 matchs par clan = 12 matchs au total (3 duos d'exempts croisés)
     * - Groupe B (5 clans) : 4 matchs par clan = 10 matchs au total (Round-Robin complet à 5)
     * Total Phase de Groupes : 22 matchs
     */
    public function generateGroupMatches(Competition $competition)
    {
        $registrations = \App\Models\ClanRegistration::with('clan')
            ->where('competition_id', $competition->id)
            ->where('status', 'confirmed')
            ->whereIn('group', ['A', 'B'])
            ->orderBy('updated_at', 'asc')
            ->get()
            ->groupBy('group');

        if ($registrations->isEmpty()) {
            return response()->json(['message' => 'Aucun clan assigné à un groupe. Effectuez d\'abord le tirage au sort.'], 422);
        }

        $groupMatchPairs = ['A' => [], 'B' => []];

        foreach (['A', 'B'] as $group) {
            $clans = collect($registrations->get($group, []))->map(fn($r) => $r->clan)->filter()->values();
            $n = $clans->count();

            if ($n < 2) continue;

            // Paires d'exemptions pour le Groupe A si 6 clans (4 matchs par clan)
            $skippedPairs = [];
            if ($group === 'A' && $n === 6) {
                $skippedPairs = [
                    '0-1' => true, '1-0' => true,
                    '2-3' => true, '3-2' => true,
                    '4-5' => true, '5-4' => true,
                ];
            }

            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (isset($skippedPairs["{$i}-{$j}"])) {
                        continue;
                    }
                    $groupMatchPairs[$group][] = [
                        'home' => $clans[$i],
                        'away' => $clans[$j],
                    ];
                }
            }
        }

        // Intercaler les matchs de la matrice (A1 vs A3, B1 vs B2, A1 vs A4, B1 vs B3...)
        $interleavedPairs = [];
        $maxCount = max(count($groupMatchPairs['A']), count($groupMatchPairs['B']));

        for ($idx = 0; $idx < $maxCount; $idx++) {
            if (isset($groupMatchPairs['A'][$idx])) {
                $interleavedPairs[] = array_merge($groupMatchPairs['A'][$idx], ['group' => 'A']);
            }
            if (isset($groupMatchPairs['B'][$idx])) {
                $interleavedPairs[] = array_merge($groupMatchPairs['B'][$idx], ['group' => 'B']);
            }
        }

        $created = 0;
        $matchNumber = \App\Models\TournamentMatch::where('competition_id', $competition->id)->max('match_number') ?? 0;

        foreach ($interleavedPairs as $pair) {
            $group = $pair['group'];
            $clanHome = $pair['home'];
            $clanAway = $pair['away'];

            $exists = \App\Models\TournamentMatch::where('competition_id', $competition->id)
                ->where('phase', 'group_stage')
                ->where('group', $group)
                ->where(function($q) use ($clanHome, $clanAway) {
                    $q->where(function($q2) use ($clanHome, $clanAway) {
                        $q2->where('clan_home_id', $clanHome->id)
                           ->where('clan_away_id', $clanAway->id);
                    })->orWhere(function($q2) use ($clanHome, $clanAway) {
                        $q2->where('clan_home_id', $clanAway->id)
                           ->where('clan_away_id', $clanHome->id);
                    });
                })->exists();

            if (!$exists) {
                $matchNumber++;
                \App\Models\TournamentMatch::create([
                    'competition_id' => $competition->id,
                    'round' => 1,
                    'group' => $group,
                    'phase' => 'group_stage',
                    'match_number' => $matchNumber,
                    'clan_home_id' => $clanHome->id,
                    'clan_away_id' => $clanAway->id,
                    'status' => 'scheduled',
                ]);
                $created++;
            }
        }

        return response()->json([
            'message' => "$created matchs de poule générés avec succès selon l'ordre du tirage !",
            'created' => $created,
        ]);
    }

    /**
     * Génère automatiquement les demi-finales (Carré d'As) selon le classement des poules :
     * - Demi-Finale 1 : 1er Groupe A vs 2ème Groupe B
     * - Demi-Finale 2 : 1er Groupe B vs 2ème Groupe A
     */
    public function generateSemiFinals(Competition $competition, \App\Services\GroupStageService $service)
    {
        $standings = $service->getGroupStandings($competition->id);

        if (empty($standings['A']) || count($standings['A']) < 2 || empty($standings['B']) || count($standings['B']) < 2) {
            return response()->json([
                'message' => 'Les classements des groupes A et B doivent contenir au moins 2 clans chacun pour générer les demi-finales.'
            ], 422);
        }

        $a1 = $standings['A'][0];
        $a2 = $standings['A'][1];
        $b1 = $standings['B'][0];
        $b2 = $standings['B'][1];

        // Créer ou mettre à jour la Demi-Finale 1 (A1 vs B2)
        $sf1 = \App\Models\TournamentMatch::updateOrCreate(
            [
                'competition_id' => $competition->id,
                'phase' => 'semi_final',
                'match_number' => 1,
            ],
            [
                'round' => 2,
                'clan_home_id' => $a1['clan_id'],
                'clan_away_id' => $b2['clan_id'],
                'status' => 'scheduled',
            ]
        );

        // Créer ou mettre à jour la Demi-Finale 2 (B1 vs A2)
        $sf2 = \App\Models\TournamentMatch::updateOrCreate(
            [
                'competition_id' => $competition->id,
                'phase' => 'semi_final',
                'match_number' => 2,
            ],
            [
                'round' => 2,
                'clan_home_id' => $b1['clan_id'],
                'clan_away_id' => $a2['clan_id'],
                'status' => 'scheduled',
            ]
        );

        return response()->json([
            'message' => 'Demi-Finales générées avec succès ! Affiches : ' . $a1['clan_name'] . ' vs ' . $b2['clan_name'] . ' & ' . $b1['clan_name'] . ' vs ' . $a2['clan_name'],
            'semi_finals' => [$sf1->load(['clanHome', 'clanAway']), $sf2->load(['clanHome', 'clanAway'])],
        ]);
    }
}


