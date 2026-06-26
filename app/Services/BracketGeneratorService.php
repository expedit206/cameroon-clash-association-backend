<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\ClanRegistration;
use App\Models\TournamentMatch;
use Illuminate\Support\Facades\DB;

/**
 * Service pour générer l'arbre de tournoi (Bracket).
 */
class BracketGeneratorService
{
    /**
     * Génère les 8èmes de finale pour une compétition donnée.
     * Prend les 16 premières inscriptions confirmées.
     */
    public function generateInitialBracket(Competition $competition)
    {
        return DB::transaction(function () use ($competition) {
            // 1. Récupérer les 16 clans confirmés ordonnés par seed_number
            $registrations = ClanRegistration::where('competition_id', $competition->id)
                ->where('status', 'confirmed')
                ->orderBy('seed_number')
                ->limit(16)
                ->get();

            if ($registrations->count() < 16) {
                throw new \Exception("Il faut 16 clans confirmés pour générer le tournoi.");
            }

            // 2. Supprimer les matches existants pour ce tournoi (pour éviter les doublons si on relance)
            TournamentMatch::where('competition_id', $competition->id)->delete();

            // 3. Appairer selon le seeding classique (1 vs 16, 2 vs 15, etc.)
            // Ou plus simple pour l'instant : 1-16, 2-15, 3-14, 4-13, 5-12, 6-11, 7-10, 8-9
            $pairs = [
                [1, 16], [8, 9], [5, 12], [4, 13],
                [3, 14], [6, 11], [7, 10], [2, 15]
            ];

            foreach ($pairs as $index => $pair) {
                $reg1 = $registrations->where('seed_number', $pair[0])->first();
                $reg2 = $registrations->where('seed_number', $pair[1])->first();

                TournamentMatch::create([
                    'competition_id' => $competition->id,
                    'round' => 1, // 8èmes
                    'match_number' => $index + 1,
                    'clan_home_id' => $reg1->clan_id,
                    'clan_away_id' => $reg2->clan_id,
                    'host_clan_id' => $reg1->clan_id, // Par défaut le mieux seedé reçoit
                    'status' => 'scheduled',
                    'scheduled_at' => now()->addDays(2), // Exemple
                ]);
            }

            return true;
        });
    }
}
