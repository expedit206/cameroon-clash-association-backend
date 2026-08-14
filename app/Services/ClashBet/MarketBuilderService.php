<?php

namespace App\Services\ClashBet;

use App\Models\BetMarket;
use App\Models\ClashBetAudit;
use App\Models\TournamentMatch;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class MarketBuilderService
{
    public function __construct(
        private readonly RuleEvaluatorService $evaluatorService,
        private readonly RuleNegatorService $negatorService
    ) {}

    /**
     * Valide et crée un nouveau marché basé sur une règle AST JSON.
     */
    public function createMarket(array $data): BetMarket
    {
        $match = TournamentMatch::with(['clanHome', 'clanAway', 'duels'])->findOrFail($data['match_id']);

        $ruleDefinition = $data['rule_definition'];
        $this->validateRuleStructure($ruleDefinition);

        // Auto-génération d'un titre si non spécifié
        $title = $data['title'] ?? $this->generateHumanTitle($ruleDefinition, $match);
        $description = $data['description'] ?? "Marché P2P basé sur des conditions vérifiables du match.";

        $market = BetMarket::create([
            'match_id'          => $match->id,
            'title'             => $title,
            'description'       => $description,
            'category'          => $data['category'] ?? 'team',
            'rule_definition'   => $ruleDefinition,
            'rule_version'      => 1,
            'status'            => 'open',
            'total_pool'        => 0,
            'betting_closes_at' => $data['betting_closes_at'] ?? $match->scheduled_at?->subMinutes(5),
        ]);

        // Audit log
        ClashBetAudit::create([
            'admin_id'   => Auth::id(),
            'event_type' => 'MARKET_CREATED',
            'market_id'  => $market->id,
            'payload'    => [
                'title'           => $title,
                'category'        => $market->category,
                'rule_definition' => $ruleDefinition,
            ],
        ]);

        return $market;
    }

    /**
     * Valide la structure de l'arbre AST.
     */
    public function validateRuleStructure(array $node): void
    {
        $type = $node['type'] ?? null;
        if (!$type || !in_array($type, ['comparison', 'comparison_entities', 'logical'])) {
            throw new InvalidArgumentException("Type de nœud invalide : {$type}");
        }

        if ($type === 'logical') {
            $operator = strtoupper($node['operator'] ?? '');
            if (!in_array($operator, ['AND', 'OR'])) {
                throw new InvalidArgumentException("Opérateur logique invalide : {$operator}");
            }
            if (empty($node['children']) || !is_array($node['children'])) {
                throw new InvalidArgumentException("Un nœud logique doit contenir au moins une condition enfant.");
            }
            foreach ($node['children'] as $child) {
                $this->validateRuleStructure($child);
            }
            return;
        }

        if ($type === 'comparison') {
            $subject = $node['subject_type'] ?? null;
            if (!in_array($subject, ['team', 'player', 'match', 'duel'])) {
                throw new InvalidArgumentException("Sujet invalide : {$subject}");
            }
            $op = $node['operator'] ?? null;
            if (!in_array($op, ['=', '!=', '>', '>=', '<', '<='])) {
                throw new InvalidArgumentException("Opérateur de comparaison invalide : {$op}");
            }
        }
    }

    /**
     * Génère un titre lisible en français à partir de la règle AST.
     */
    public function generateHumanTitle(array $node, TournamentMatch $match): string
    {
        $type = $node['type'] ?? 'comparison';

        if ($type === 'logical') {
            $op = strtoupper($node['operator'] ?? 'AND');
            $subTitles = array_map(fn($c) => $this->generateHumanTitle($c, $match), $node['children'] ?? []);
            return implode(" " . ($op === 'AND' ? 'ET' : 'OU') . " ", $subTitles);
        }

        if ($type === 'comparison') {
            $subject = $node['subject_type'] ?? 'team';
            $target  = $node['target'] ?? 'home';
            $metric  = $node['metric'] ?? 'total_stars';
            $op      = $node['operator'] ?? '>=';
            $val     = $node['value'] ?? 0;

            $entityName = 'Le match';
            if ($subject === 'duel') {
                $hdv = intval($target ?: 16);
                $duel = $match->duels?->first(fn($d) => intval($d->hdv_level) === $hdv);
                $pHome = $duel?->player_home_name ?? ($match->clanHome?->name ? "Joueur {$match->clanHome->name}" : "Joueur Hôte");
                $pAway = $duel?->player_away_name ?? ($match->clanAway?->name ? "Joueur {$match->clanAway->name}" : "Joueur Invité");

                if ($metric === 'winner') {
                    $winnerName = ($val === 'home') ? $pHome : (($val === 'away') ? $pAway : $pHome);
                    $loserName  = ($val === 'home') ? $pAway : $pHome;
                    return "[Duel HDV{$hdv}] {$winnerName} remporte le duel face à {$loserName}";
                }
                return "[Duel HDV{$hdv}] {$pHome} VS {$pAway}";
            } elseif ($subject === 'team') {
                $entityName = ($target === 'home')
                    ? ($match->clanHome?->name ?? 'Clan Hôte')
                    : ($match->clanAway?->name ?? 'Clan Invité');
            } elseif ($subject === 'player') {
                $duel = $match->duels->first(fn($d) => $d->player_home_id == $target || $d->player_away_id == $target);
                if ($duel) {
                    $entityName = ($duel->player_home_id == $target)
                        ? ($duel->playerHome?->name ?? "Joueur #{$target}")
                        : ($duel->playerAway?->name ?? "Joueur #{$target}");
                } else {
                    $entityName = "Joueur #{$target}";
                }
            }

            $metricLabel = match ($metric) {
                'total_stars'         => 'étoiles',
                'average_destruction' => '% de destruction',
                'three_star_count'    => 'attaques 3★',
                'two_star_count'      => 'attaques 2★',
                'one_star_count'      => 'attaques 1★',
                'stars'               => 'étoiles',
                'winner'              => 'gagne le match',
                default               => $metric,
            };

            $opLabel = match ($op) {
                '>=' => 'au moins',
                '>'  => 'plus de',
                '='  => 'exactement',
                '!=' => 'différent de',
                '<=' => 'au maximum',
                '<'  => 'moins de',
                default => $op,
            };

            return "{$entityName} aura {$opLabel} {$val} {$metricLabel}";
        }

        return "Marché Spécial";
    }

    /**
     * Génère un lot de marchés standards préconfigurés pour un match.
     */
    public function bulkGenerateStandardMarkets(TournamentMatch $match): array
    {
        $match->loadMissing(['clanHome', 'clanAway', 'duels.playerHome', 'duels.playerAway']);
        $created = [];

        // 1. Vainqueur du Match (Home vs Away)
        $created[] = $this->createMarket([
            'match_id'        => $match->id,
            'title'           => "{$match->clanHome?->name} gagne le match",
            'category'        => 'team',
            'rule_definition' => [
                'type'         => 'comparison',
                'subject_type' => 'match',
                'metric'       => 'winner',
                'operator'     => '=',
                'value'        => 'home',
            ],
        ]);

        // 2. Étoiles Clan Hôte (Thresholds 8, 9, 10, 11, 12)
        foreach ([8, 10, 12] as $threshold) {
            $created[] = $this->createMarket([
                'match_id'        => $match->id,
                'title'           => "{$match->clanHome?->name} aura au moins {$threshold} étoiles",
                'category'        => 'team',
                'rule_definition' => [
                    'type'         => 'comparison',
                    'subject_type' => 'team',
                    'target'       => 'home',
                    'metric'       => 'total_stars',
                    'operator'     => '>=',
                    'value'        => $threshold,
                ],
            ]);
        }

        // 3. Étoiles Clan Invité (Thresholds 8, 10, 12)
        foreach ([8, 10, 12] as $threshold) {
            $created[] = $this->createMarket([
                'match_id'        => $match->id,
                'title'           => "{$match->clanAway?->name} aura au moins {$threshold} étoiles",
                'category'        => 'team',
                'rule_definition' => [
                    'type'         => 'comparison',
                    'subject_type' => 'team',
                    'target'       => 'away',
                    'metric'       => 'total_stars',
                    'operator'     => '>=',
                    'value'        => $threshold,
                ],
            ]);
        }

        // 4. Attaques 3 Étoiles pour le Clan Hôte (>= 2)
        $created[] = $this->createMarket([
            'match_id'        => $match->id,
            'title'           => "{$match->clanHome?->name} fera au moins 2 attaques 3★",
            'category'        => 'team',
            'rule_definition' => [
                'type'         => 'comparison',
                'subject_type' => 'team',
                'target'       => 'home',
                'metric'       => 'three_star_count',
                'operator'     => '>=',
                'value'        => 2,
            ],
        ]);

        return $created;
    }
}
