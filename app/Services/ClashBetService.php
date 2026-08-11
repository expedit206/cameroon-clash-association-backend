<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bet;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Models\RegistrationPlayer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moteur central du module CCA Clash Bet.
 *
 * Gère le placement atomique des paris, la vérification de liquidité,
 * la distribution des gains et le remboursement en cas d'annulation.
 */
class ClashBetService
{
    public const WITHDRAWAL_FEE_RATE = 0.07; // 7% sur les retraits uniquement

    public function __construct(
        private readonly ClashBetOddsService $oddsService
    ) {}

    /**
     * Place un pari de manière atomique.
     *
     * @param  User       $user           L'utilisateur qui parie
     * @param  BetOption  $option         L'option choisie (clan A ou clan B)
     * @param  int        $amount         Montant en FCFA
     * @param  float      $expectedOdds   Cote affichée au moment du clic
     * @return array      ['bet' => Bet, 'slippage' => bool, 'executed_odds' => float]
     *
     * @throws \Exception si validation échoue
     */
    public function placeBet(User $user, BetOption $option, int $amount, float $expectedOdds): array
    {
        return DB::transaction(function () use ($user, $option, $amount, $expectedOdds) {
            // 1. Charger le marché avec un verrou pour éviter la concurrence
            $market = BetMarket::lockForUpdate()->findOrFail($option->market_id);

            // 2. Vérifications préalables
            $this->validatePreBetChecks($user, $market, $amount);

            // 3. Calcul de la cote exécutée RÉELLE au moment de l'exécution
            $executedOdds = $this->oddsService->computeOdds($market, $option);
            $hasSlippage  = $this->oddsService->hasSlippage($expectedOdds, $executedOdds);

            // 4. Calcul du gain potentiel brut
            $potentialPayout = (int) round($amount * $executedOdds);

            // 5. Vérification de liquidité : le pool perdant peut-il couvrir ce gain ?
            $this->validateLiquidity($market, $option, $potentialPayout);

            // 6. Débit atomique du wallet
            $wallet = $user->getOrCreateWallet();
            $wallet->lockForBet(
                $amount,
                "Pari sur {$option->label} — Match #{$market->match_id}",
                null
            );

            // 7. Création du pari
            $bet = Bet::create([
                'user_id'         => $user->id,
                'market_id'       => $market->id,
                'option_id'       => $option->id,
                'amount'          => $amount,
                'executed_odds'   => $executedOdds,
                'potential_payout'=> $potentialPayout,
                'status'          => 'pending',
            ]);

            // Mise à jour de la référence wallet transaction
            $wallet->transactions()->latest()->first()?->update(['reference' => (string) $bet->id]);

            // 8. Mise à jour des pools du marché
            $option->increment('current_pool', $amount);
            $option->increment('reserved_payout', $potentialPayout);
            $market->increment('total_pool', $amount);

            Log::info("Clash Bet: Pari #{$bet->id} placé — User #{$user->id}, {$amount} FCFA @ {$executedOdds}");

            return [
                'bet'           => $bet,
                'slippage'      => $hasSlippage,
                'executed_odds' => $executedOdds,
            ];
        });
    }

    /**
     * Règle un marché en distribuant les gains aux gagnants.
     * 100% du pool est redistribué proportionnellement aux cotes exécutées.
     * CCA ne prélève rien sur le pool — son revenu est uniquement les frais de retrait.
     */
    public function settleMarket(BetMarket $market, int $winnerOptionId): array
    {
        return DB::transaction(function () use ($market, $winnerOptionId) {
            $market = BetMarket::lockForUpdate()->findOrFail($market->id);

            if ($market->status !== 'closed') {
                // Fermer d'abord si encore ouvert
                $market->update(['status' => 'closed']);
            }

            $stats = ['winners' => 0, 'losers' => 0, 'total_paid' => 0, 'refunded' => 0];

            // Bets gagnants → créditer le gain
            $winningBets = Bet::where('market_id', $market->id)
                ->where('option_id', $winnerOptionId)
                ->where('status', 'pending')
                ->with('user')
                ->get();

            foreach ($winningBets as $bet) {
                $wallet = $bet->user->getOrCreateWallet();
                $optionLabel = $bet->option->label ?? 'option';
                $wallet->unlockAndCredit(
                    $bet->amount,          // déverrouiller la mise
                    $bet->potential_payout,// créditer le gain brut (à la cote exécutée)
                    'bet_win',
                    "Gain: {$optionLabel} @ {$bet->executed_odds}",
                    (string) $bet->id
                );

                $bet->update([
                    'status'        => 'won',
                    'actual_payout' => $bet->potential_payout,
                    'settled_at'    => now(),
                ]);

                $stats['winners']++;
                $stats['total_paid'] += $bet->potential_payout;
            }

            // Bets perdants → déverrouiller sans créditer
            $losingBets = Bet::where('market_id', $market->id)
                ->where('option_id', '!=', $winnerOptionId)
                ->where('status', 'pending')
                ->with('user')
                ->get();

            foreach ($losingBets as $bet) {
                $wallet = $bet->user->getOrCreateWallet();
                // Déverrouiller le montant (il était déjà déduit du balance)
                $wallet->decrement('locked_amount', $bet->amount);

                $bet->update([
                    'status'        => 'lost',
                    'actual_payout' => 0,
                    'settled_at'    => now(),
                ]);
                $stats['losers']++;
            }

            $market->update(['status' => 'settled']);

            Log::info("Clash Bet: Marché #{$market->id} réglé — {$stats['winners']} gagnants, {$stats['total_paid']} FCFA distribués");

            return $stats;
        });
    }

    /**
     * Annule un marché et rembourse tous les paris.
     */
    public function cancelMarket(BetMarket $market, string $reason): void
    {
        DB::transaction(function () use ($market, $reason) {
            $pendingBets = Bet::where('market_id', $market->id)
                ->where('status', 'pending')
                ->with('user')
                ->get();

            foreach ($pendingBets as $bet) {
                $wallet = $bet->user->getOrCreateWallet();
                $wallet->unlockAndCredit(
                    $bet->amount,
                    $bet->amount,
                    'bet_refund',
                    "Remboursement: marché annulé — {$reason}",
                    (string) $bet->id
                );

                $bet->update([
                    'status'        => 'refunded',
                    'actual_payout' => $bet->amount,
                    'settled_at'    => now(),
                ]);
            }

            $market->update([
                'status'           => 'cancelled',
                'cancelled_reason' => $reason,
            ]);
        });
    }

    /**
     * Validations préalables avant de placer un pari.
     */
    private function validatePreBetChecks(User $user, BetMarket $market, int $amount): void
    {
        if (!$market->isOpen()) {
            throw new \Exception('Ce marché n\'est plus ouvert aux paris.');
        }

        if ($amount < 100) {
            throw new \Exception('Mise minimale : 100 FCFA.');
        }

        if ($amount > 50000) {
            throw new \Exception('Mise maximale : 50 000 FCFA.');
        }

        // Anti-match-fixing : les joueurs du roster NE peuvent PAS parier
        $matchId = $market->match_id;
        $match   = $market->match ?? $market->match()->first();

        $isRosterPlayer = RegistrationPlayer::whereHas('registration', function ($q) use ($matchId, $match) {
            $q->whereIn('clan_id', array_filter([
                $match?->clan_home_id,
                $match?->clan_away_id,
            ]));
        })->where('player_id', $user->id)->exists();

        if ($isRosterPlayer) {
            throw new \Exception('Les joueurs participant à ce match ne peuvent pas y parier.');
        }

        // Vérification du solde
        $wallet = $user->getOrCreateWallet();
        if ($wallet->balance < $amount) {
            throw new \Exception("Solde insuffisant. Disponible : {$wallet->balance} FCFA.");
        }
    }

    /**
     * Vérifie que le pool perdant peut financer le gain potentiel de cette nouvelle mise.
     */
    private function validateLiquidity(BetMarket $market, BetOption $chosenOption, int $potentialPayout): void
    {
        // Pool effectif incluant la liquidité virtuelle de départ (liquidity_weight)
        $effectivePool = $market->total_pool + ($market->liquidity_weight ?? 100000);
        $alreadyReserved = $chosenOption->reserved_payout ?? 0;

        // Le seuil de sécurité autorise les gains jusqu'à 3× le pool effectif
        if (($alreadyReserved + $potentialPayout) > ($effectivePool * 3)) {
            throw new \Exception('Liquidité insuffisante sur ce marché pour accepter cette mise.');
        }
    }
}
