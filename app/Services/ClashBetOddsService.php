<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BetMarket;
use App\Models\BetOption;

/**
 * Service de calcul des cotes dynamiques avec Bonding Curve.
 *
 * Formule: Cote = (pool_total + liquidity_weight) / (pool_option + liquidity_weight / 2)
 *
 * Le liquidity_weight agit comme un "amortisseur" empêchant les variations brutales
 * sur les petits marchés ou lors de grosses mises isolées.
 */
class ClashBetOddsService
{
    public const MIN_ODDS = 1.01;
    public const MAX_ODDS = 50.0;

    /**
     * Calcule la cote actuelle d'une option.
     */
    public function computeOdds(BetMarket $market, BetOption $option): float
    {
        $liquidityWeight = $market->liquidity_weight;
        $totalPool       = $market->total_pool;
        $optionPool      = $option->current_pool;

        // Si aucune mise encore, cote initiale équilibrée = 2.00 (marché 2 options)
        if ($totalPool === 0) {
            return 2.0;
        }

        $numerator   = $totalPool + $liquidityWeight;
        $denominator = $optionPool + ($liquidityWeight / 2);

        if ($denominator <= 0) {
            return self::MAX_ODDS;
        }

        $odds = $numerator / $denominator;

        return $this->clampOdds($odds);
    }

    /**
     * Calcule les cotes pour toutes les options d'un marché.
     */
    public function computeAllOdds(BetMarket $market): array
    {
        $market->load('options');
        $result = [];
        foreach ($market->options as $option) {
            $result[$option->id] = $this->computeOdds($market, $option);
        }
        return $result;
    }

    /**
     * Simule la cote après une mise (pour affichage avant confirmation).
     * Permet d'évaluer l'impact sur la cote SANS alter la BDD.
     */
    public function simulateOdds(BetMarket $market, BetOption $option, int $betAmount): float
    {
        $liquidityWeight    = $market->liquidity_weight;
        $simulatedTotalPool = $market->total_pool + $betAmount;
        $simulatedOptionPool = $option->current_pool + $betAmount;

        if ($simulatedTotalPool === 0) {
            return 2.0;
        }

        $numerator   = $simulatedTotalPool + $liquidityWeight;
        $denominator = $simulatedOptionPool + ($liquidityWeight / 2);

        if ($denominator <= 0) {
            return self::MAX_ODDS;
        }

        return $this->clampOdds($numerator / $denominator);
    }

    /**
     * Vérifie si le delta de cote dépasse le seuil de slippage.
     */
    public function hasSlippage(float $expectedOdds, float $actualOdds, float $threshold = 0.05): bool
    {
        return abs($actualOdds - $expectedOdds) > $threshold;
    }

    private function clampOdds(float $odds): float
    {
        return round(max(self::MIN_ODDS, min(self::MAX_ODDS, $odds)), 2);
    }
}
