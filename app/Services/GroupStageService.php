<?php

namespace App\Services;

use App\Models\ClanRegistration;
use App\Models\TournamentMatch;
use App\Models\Competition;

class GroupStageService
{
    /**
     * Calcule le classement complet pour les 2 groupes (A et B) d'une compétition.
     */
    public function getGroupStandings(int $competitionId)
    {
        return [
            'A' => $this->calculateStandingsForGroup($competitionId, 'A'),
            'B' => $this->calculateStandingsForGroup($competitionId, 'B'),
        ];
    }

    /**
     * Calcule le classement d'un groupe spécifique ('A' ou 'B').
     */
    public function calculateStandingsForGroup(int $competitionId, string $group)
    {
        // 1. Récupérer les clans enregistrés dans ce groupe
        $registrations = ClanRegistration::with('clan')
            ->where('competition_id', $competitionId)
            ->where('group', $group)
            ->get();

        // 2. Récupérer tous les matches de ce groupe
        $matches = TournamentMatch::where('competition_id', $competitionId)
            ->where(function ($query) use ($group) {
                $query->where('group', $group)
                      ->orWhere('phase', 'group_stage');
            })
            ->where('status', 'completed')
            ->get();

        $stats = [];

        foreach ($registrations as $reg) {
            $clan = $reg->clan;
            if (!$clan) continue;

            $stats[$clan->id] = [
                'clan_id' => $clan->id,
                'clan_name' => $clan->name,
                'clan_tag' => $clan->tag_coc,
                'badge_url' => $clan->badge_url,
                'group' => $group,
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'points' => 0,
                'total_stars' => 0,
                'total_destruction' => 0.0,
            ];
        }

        // Parcourir les matches complétés
        foreach ($matches as $match) {
            $homeId = $match->clan_home_id;
            $awayId = $match->clan_away_id;

            // Ne traiter que si au moins un des deux clans appartient au groupe
            $homeInGroup = isset($stats[$homeId]);
            $awayInGroup = isset($stats[$awayId]);

            if (!$homeInGroup && !$awayInGroup) continue;

            $homeStars = (int) ($match->total_stars_home ?? 0);
            $awayStars = (int) ($match->total_stars_away ?? 0);
            $homeDest = (float) ($match->total_destruction_home ?? 0);
            $awayDest = (float) ($match->total_destruction_away ?? 0);

            if ($homeInGroup) {
                $stats[$homeId]['played'] += 1;
                $stats[$homeId]['total_stars'] += $homeStars;
                $stats[$homeId]['total_destruction'] += $homeDest;
            }

            if ($awayInGroup) {
                $stats[$awayId]['played'] += 1;
                $stats[$awayId]['total_stars'] += $awayStars;
                $stats[$awayId]['total_destruction'] += $awayDest;
            }

            // Déterminer le vainqueur
            if ($homeStars > $awayStars) {
                if ($homeInGroup) { $stats[$homeId]['won'] += 1; $stats[$homeId]['points'] += 3; }
                if ($awayInGroup) { $stats[$awayId]['lost'] += 1; }
            } elseif ($awayStars > $homeStars) {
                if ($awayInGroup) { $stats[$awayId]['won'] += 1; $stats[$awayId]['points'] += 3; }
                if ($homeInGroup) { $stats[$homeId]['lost'] += 1; }
            } else {
                // Egalité étoiles -> départage au % destruction
                if ($homeDest > $awayDest) {
                    if ($homeInGroup) { $stats[$homeId]['won'] += 1; $stats[$homeId]['points'] += 3; }
                    if ($awayInGroup) { $stats[$awayId]['lost'] += 1; }
                } elseif ($awayDest > $homeDest) {
                    if ($awayInGroup) { $stats[$awayId]['won'] += 1; $stats[$awayId]['points'] += 3; }
                    if ($homeInGroup) { $stats[$homeId]['lost'] += 1; }
                } else {
                    // Match nul parfait
                    if ($homeInGroup) { $stats[$homeId]['drawn'] += 1; $stats[$homeId]['points'] += 1; }
                    if ($awayInGroup) { $stats[$awayId]['drawn'] += 1; $stats[$awayId]['points'] += 1; }
                }
            }
        }

        // Calcul du % de destruction moyen (total / joués) pour chaque clan
        foreach ($stats as &$row) {
            $row['avg_destruction'] = $row['played'] > 0 
                ? round($row['total_destruction'] / $row['played'], 2) 
                : 0.0;
        }
        unset($row);

        // Convertir en tableau réindexé et trier
        $standings = array_values($stats);

        usort($standings, function ($a, $b) {
            if ($a['points'] !== $b['points']) {
                return $b['points'] <=> $a['points'];
            }
            if ($a['total_stars'] !== $b['total_stars']) {
                return $b['total_stars'] <=> $a['total_stars'];
            }
            // Départage au % de destruction moyen par match
            if ($a['avg_destruction'] !== $b['avg_destruction']) {
                return $b['avg_destruction'] <=> $a['avg_destruction'];
            }
            if ($a['total_destruction'] !== $b['total_destruction']) {
                return $b['total_destruction'] <=> $a['total_destruction'];
            }
            return strcmp($a['clan_name'], $b['clan_name']);
        });

        // Ajouter la position (1..N) et uniformiser total_destruction avec la moyenne
        foreach ($standings as $index => &$row) {
            $row['rank'] = $index + 1;
            $row['total_destruction_sum'] = $row['total_destruction'];
            $row['total_destruction'] = $row['avg_destruction'];
        }

        return $standings;
    }
}
