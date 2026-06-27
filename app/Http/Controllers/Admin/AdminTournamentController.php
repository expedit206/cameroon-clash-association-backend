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
            ->orderBy('round')
            ->orderBy('match_number')
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
            'total_stars_home' => 'required|integer|min:0|max:15',
            'total_stars_away' => 'required|integer|min:0|max:15',
            'total_destruction_home' => 'nullable|numeric|min:0|max:100',
            'total_destruction_away' => 'nullable|numeric|min:0|max:100',
            'status' => 'required|string|in:scheduled,in_progress,completed,forfeit',
        ]);

        $match->update($request->all());

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

            // Tenter de faire progresser le tournoi
            $service = new \App\Services\BracketGeneratorService();
            $service->advanceTournament($match->competition, $match->round);
        }

        return response()->json(['message' => 'Match mis à jour.', 'match' => $match->load(['clanHome', 'clanAway'])]);
    }
}
