<?php

namespace App\Services\ClashBet;

use App\Models\TournamentMatch;

class RuleEvaluatorService
{
    /**
     * Évalue une règle AST JSON par rapport à un match officiel.
     */
    public function evaluateMatch(array $rule, TournamentMatch $match): array
    {
        $dataset = $this->extractDatasetFromMatch($match);
        $result  = $this->evaluateRule($rule, $dataset);

        return [
            'result'      => $result,
            'winning_side'=> $result ? 'YES' : 'NO',
            'snapshot'    => [
                'evaluated_at' => now()->toIso8601String(),
                'rule'         => $rule,
                'dataset'      => $dataset,
                'result'       => $result,
            ],
        ];
    }

    /**
     * Évalue une règle par rapport à un jeu de données simulé.
     */
    public function evaluateMock(array $rule, array $mockDataset): array
    {
        $result = $this->evaluateRule($rule, $mockDataset);

        return [
            'result'      => $result,
            'winning_side'=> $result ? 'YES' : 'NO',
            'snapshot'    => [
                'evaluated_at' => now()->toIso8601String(),
                'rule'         => $rule,
                'dataset'      => $mockDataset,
                'result'       => $result,
            ],
        ];
    }

    /**
     * Extrait toutes les variables de métrique d'un match et de ses duels.
     */
    public function extractDatasetFromMatch(TournamentMatch $match): array
    {
        $match->loadMissing(['duels.playerHome', 'duels.playerAway']);

        // Stats Équipe Home & Away
        $duelsHomeStars = $match->duels->pluck('stars_home')->filter(fn($v) => !is_null($v));
        $duelsAwayStars = $match->duels->pluck('stars_away')->filter(fn($v) => !is_null($v));

        $threeStarsHome = $duelsHomeStars->filter(fn($s) => $s === 3)->count();
        $twoStarsHome   = $duelsHomeStars->filter(fn($s) => $s === 2)->count();
        $oneStarsHome   = $duelsHomeStars->filter(fn($s) => $s === 1)->count();
        $zeroStarsHome  = $duelsHomeStars->filter(fn($s) => $s === 0)->count();

        $threeStarsAway = $duelsAwayStars->filter(fn($s) => $s === 3)->count();
        $twoStarsAway   = $duelsAwayStars->filter(fn($s) => $s === 2)->count();
        $oneStarsAway   = $duelsAwayStars->filter(fn($s) => $s === 1)->count();
        $zeroStarsAway  = $duelsAwayStars->filter(fn($s) => $s === 0)->count();

        // Calcul du vainqueur du match
        $winnerStr = 'draw';
        if ($match->total_stars_home > $match->total_stars_away) {
            $winnerStr = 'home';
        } elseif ($match->total_stars_away > $match->total_stars_home) {
            $winnerStr = 'away';
        } else {
            if ($match->total_destruction_home > $match->total_destruction_away) {
                $winnerStr = 'home';
            } elseif ($match->total_destruction_away > $match->total_destruction_home) {
                $winnerStr = 'away';
            }
        }

        $dataset = [
            'match' => [
                'winner'                 => $winnerStr,
                'total_stars_difference' => abs(($match->total_stars_home ?? 0) - ($match->total_stars_away ?? 0)),
                'total_attacks'          => ($match->attacks_home ?? 0) + ($match->attacks_away ?? 0),
            ],
            'team' => [
                'home' => [
                    'total_stars'         => (int) ($match->total_stars_home ?? 0),
                    'average_destruction' => (float) ($match->total_destruction_home ?? 0),
                    'total_destruction'   => (float) ($match->total_destruction_home ?? 0),
                    'three_star_count'    => $threeStarsHome,
                    'two_star_count'      => $twoStarsHome,
                    'one_star_count'      => $oneStarsHome,
                    'zero_star_count'     => $zeroStarsHome,
                    'attack_count'        => (int) ($match->attacks_home ?? 0),
                ],
                'away' => [
                    'total_stars'         => (int) ($match->total_stars_away ?? 0),
                    'average_destruction' => (float) ($match->total_destruction_away ?? 0),
                    'total_destruction'   => (float) ($match->total_destruction_away ?? 0),
                    'three_star_count'    => $threeStarsAway,
                    'two_star_count'      => $twoStarsAway,
                    'one_star_count'      => $oneStarsAway,
                    'zero_star_count'     => $zeroStarsAway,
                    'attack_count'        => (int) ($match->attacks_away ?? 0),
                ],
            ],
            'player' => [],
        ];

        // Stats Joueurs par Duel
        foreach ($match->duels as $duel) {
            if ($duel->player_home_id) {
                $dataset['player'][$duel->player_home_id] = [
                    'stars'       => (int) ($duel->stars_home ?? 0),
                    'destruction' => (float) ($duel->destruction_home ?? 0),
                    'three_star_count' => ($duel->stars_home === 3) ? 1 : 0,
                    'two_star_count'   => ($duel->stars_home === 2) ? 1 : 0,
                    'one_star_count'   => ($duel->stars_home === 1) ? 1 : 0,
                ];
            }
            if ($duel->player_away_id) {
                $dataset['player'][$duel->player_away_id] = [
                    'stars'       => (int) ($duel->stars_away ?? 0),
                    'destruction' => (float) ($duel->destruction_away ?? 0),
                    'three_star_count' => ($duel->stars_away === 3) ? 1 : 0,
                    'two_star_count'   => ($duel->stars_away === 2) ? 1 : 0,
                    'one_star_count'   => ($duel->stars_away === 1) ? 1 : 0,
                ];
            }
        }

        return $dataset;
    }

    /**
     * Évaluation récursive d'un nœud de règle AST.
     */
    public function evaluateRule(array $node, array $dataset): bool
    {
        $type = $node['type'] ?? 'comparison';

        if ($type === 'logical') {
            $operator = strtoupper($node['operator'] ?? 'AND');
            $children = $node['children'] ?? [];

            if (empty($children)) {
                return true;
            }

            if ($operator === 'AND') {
                foreach ($children as $child) {
                    if (!$this->evaluateRule($child, $dataset)) {
                        return false;
                    }
                }
                return true;
            }

            if ($operator === 'OR') {
                foreach ($children as $child) {
                    if ($this->evaluateRule($child, $dataset)) {
                        return true;
                    }
                }
                return false;
            }

            return true;
        }

        if ($type === 'comparison_entities') {
            $leftVal  = $this->resolveMetricValue($node['left'] ?? [], $dataset);
            $rightVal = $this->resolveMetricValue($node['right'] ?? [], $dataset);
            $op       = $node['operator'] ?? '=';

            return $this->compareValues($leftVal, $op, $rightVal);
        }

        // Standard comparison
        $leftVal  = $this->resolveMetricValue($node, $dataset);
        $op       = $node['operator'] ?? '=';
        $rightVal = $node['value'] ?? 0;

        return $this->compareValues($leftVal, $op, $rightVal);
    }

    /**
     * Résout la valeur numérique/chaîne d'une métrique depuis le dataset.
     */
    private function resolveMetricValue(array $spec, array $dataset)
    {
        $subjectType = $spec['subject_type'] ?? 'team';
        $target      = $spec['target'] ?? 'home';
        $metric      = $spec['metric'] ?? 'total_stars';

        if ($subjectType === 'match') {
            return $dataset['match'][$metric] ?? 0;
        }

        if ($subjectType === 'team') {
            $teamKey = in_array($target, ['home', 'away']) ? $target : 'home';
            return $dataset['team'][$teamKey][$metric] ?? 0;
        }

        if ($subjectType === 'player') {
            return $dataset['player'][$target][$metric] ?? 0;
        }

        return 0;
    }

    /**
     * Effectue la comparaison logique entre deux valeurs.
     */
    private function compareValues($left, string $op, $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            $left  = (float) $left;
            $right = (float) $right;
        }

        return match ($op) {
            '='  => $left == $right,
            '!=' => $left != $right,
            '>'  => $left > $right,
            '>=' => $left >= $right,
            '<'  => $left < $right,
            '<=' => $left <= $right,
            default => false,
        };
    }
}
