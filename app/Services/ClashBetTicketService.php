<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Models\BetTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moteur central du module CCA Clash Bet — Système P2P par Tickets.
 *
 * LA PLATEFORME NE PARIE JAMAIS CONTRE LES JOUEURS.
 * Elle sert uniquement d'intermédiaire entre deux utilisateurs.
 */
class ClashBetTicketService
{
    public function __construct(
        private readonly ClashBet\RuleEvaluatorService $evaluatorService
    ) {}

    // ─── Création d'un Ticket ─────────────────────────────────────────────────

    /**
     * Crée un ticket P2P (YES ou NO) et bloque la mise du créateur.
     *
     * @throws \Exception
     */
    public function createTicket(User $creator, BetMarket $market, string $side, int $amount): BetTicket
    {
        $side = strtoupper($side) === 'NO' ? 'NO' : 'YES';

        return DB::transaction(function () use ($creator, $market, $side, $amount) {
            // 1. Charger et verrouiller le marché
            $market = BetMarket::lockForUpdate()->findOrFail($market->id);

            // 2. Validations
            $this->validateMarketOpen($market);
            $this->validateAmount($amount);
            $this->validateBalance($creator, $amount);

            // 3. Récupérer la config figée (pas de commission sur le ticket, 100% des gains au joueur)
            $odds                  = AppSetting::clashBetFixedOdds();
            $commissionPercentage  = 0;
            $grossPayout           = (int) round($amount * $odds);
            $commissionAmount      = 0;
            $netPayout             = $grossPayout;

            // 4. Bloquer la mise dans le wallet
            $wallet = $creator->getOrCreateWallet();
            $wallet->lockTicket(
                $amount,
                "Ticket {$side} — {$market->title} (#{$market->id})"
            );

            // 5. Créer le ticket
            $ticket = BetTicket::create([
                'ticket_number'         => BetTicket::generateTicketNumber(),
                'market_id'             => $market->id,
                'creator_id'            => $creator->id,
                'side'                  => $side,
                'rule_version'          => $market->rule_version ?? 1,
                'amount'                => $amount,
                'odds'                  => $odds,
                'gross_payout'          => $grossPayout,
                'commission_percentage' => $commissionPercentage,
                'commission_amount'     => $commissionAmount,
                'net_payout'            => $netPayout,
                'status'                => 'open',
            ]);

            Log::info("Clash Bet P2P: Ticket #{$ticket->ticket_number} créé — User #{$creator->id}, Position {$side}, {$amount} FCFA");

            return $ticket;
        });
    }

    // ─── Matching d'un Ticket ─────────────────────────────────────────────────

    /**
     * Matching atomique P2P YES/NO : Utilisateur B accepte le ticket de A.
     * Le taker prend automatiquement la position opposée (YES ↔ NO).
     *
     * @throws \Exception
     */
    public function matchTicket(User $taker, BetTicket $ticket): BetTicket
    {
        return DB::transaction(function () use ($taker, $ticket) {
            // 1. Re-charger avec verrou exclusif pour prévenir le double matching
            $ticket = BetTicket::lockForUpdate()->findOrFail($ticket->id);
            $market = BetMarket::lockForUpdate()->findOrFail($ticket->market_id);

            // 2. Vérifications
            if (!$ticket->isOpen()) {
                throw new \Exception('Ce ticket n\'est plus disponible.');
            }

            if ($taker->id === $ticket->creator_id) {
                throw new \Exception('Vous ne pouvez pas prendre votre propre ticket.');
            }

            $this->validateMarketOpen($market);
            $this->validateBalance($taker, $ticket->amount);

            // 3. Le taker prend la position opposée automatiquement (YES ↔ NO)
            $takerSide = (strtoupper($ticket->side ?? 'YES') === 'YES') ? 'NO' : 'YES';

            // 4. Bloquer la mise du preneur
            $takerWallet = $taker->getOrCreateWallet();
            $takerWallet->lockTicket(
                $ticket->amount,
                "Ticket pris #{$ticket->ticket_number} — Position {$takerSide} — {$market->title}"
            );

            // 5. Mettre à jour le ticket
            $ticket->update([
                'taker_id'        => $taker->id,
                'status'          => 'matched',
                'matched_at'      => now(),
                'risk_score'      => $this->computeRiskScore($ticket->creator_id, $taker->id),
                'review_required' => $this->computeRiskScore($ticket->creator_id, $taker->id) >= 75,
            ]);

            Log::info("Clash Bet P2P: Ticket #{$ticket->ticket_number} apparié — Créateur {$ticket->side} / Preneur {$takerSide} — User #{$taker->id}");

            return $ticket->fresh(['creator', 'taker', 'market']);
        });
    }

    // ─── Annulation d'un Ticket ───────────────────────────────────────────────

    /**
     * Annulation par le créateur (uniquement si OPEN).
     *
     * @throws \Exception
     */
    public function cancelTicket(User $creator, BetTicket $ticket): void
    {
        DB::transaction(function () use ($creator, $ticket) {
            $ticket = BetTicket::lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->creator_id !== $creator->id) {
                throw new \Exception('Vous ne pouvez annuler que vos propres tickets.');
            }

            if (!$ticket->canBeCancelled()) {
                throw new \Exception('Ce ticket ne peut plus être annulé (statut: ' . $ticket->status . ').');
            }

            // Rembourser la mise du créateur
            $wallet = $creator->getOrCreateWallet();
            $wallet->unlockTicket(
                $ticket->amount,
                'bet_cancel',
                "Annulation ticket #{$ticket->ticket_number}",
                (string) $ticket->id
            );

            $ticket->update(['status' => 'cancelled']);

            Log::info("Clash Bet P2P: Ticket #{$ticket->ticket_number} annulé — User #{$creator->id}");
        });
    }

    // ─── Règlement du Marché ──────────────────────────────────────────────────

    /**
     * Règle tous les tickets MATCHED/LOCKED d'un marché selon l'option gagnante.
     * Prélève la commission et crédite le gagnant.
     *
     * @return array ['settled' => int, 'total_paid' => int, 'total_commission' => int]
     */
    public function settleMarket(BetMarket $market, int $winningOptionId): array
    {
        return DB::transaction(function () use ($market, $winningOptionId) {
            // Rejeter si déjà réglé
            if ($market->status === 'settled') {
                throw new \Exception('Ce marché a déjà été réglé.');
            }

            // Fermer le marché
            $market->update(['status' => 'closed']);

            $stats = ['settled' => 0, 'total_paid' => 0, 'total_commission' => 0];

            // Récupérer tous les tickets matchés / verrouillés
            $tickets = BetTicket::lockForUpdate()
                ->where('market_id', $market->id)
                ->whereIn('status', ['matched', 'locked'])
                ->with(['creator', 'taker', 'creatorOption', 'takerOption'])
                ->get();

            foreach ($tickets as $ticket) {
                // Déterminer le gagnant
                $creatorWon = $ticket->creator_option_id === $winningOptionId;
                $winner     = $creatorWon ? $ticket->creator : $ticket->taker;
                $loser      = $creatorWon ? $ticket->taker  : $ticket->creator;

                if (!$winner || !$loser) {
                    // Ticket incomplet : rembourser le créateur
                    $this->refundSingleTicket($ticket, 'Marché réglé — ticket incomplet');
                    continue;
                }

                // Créditer le gagnant (mise débloquée + gain net)
                $winnerWallet = $winner->getOrCreateWallet();
                $winnerWallet->settleTicketWin(
                    $ticket->amount,      // montant à déverrouiller
                    $ticket->net_payout,  // gain net crédité (Pot - commission)
                    "Gain ticket #{$ticket->ticket_number}"
                );

                // Déverrouiller la mise du perdant (sans créditer)
                $loserWallet = $loser->getOrCreateWallet();
                $loserWallet->settleTicketLoss(
                    $ticket->amount,
                    "Mise perdue ticket #{$ticket->ticket_number}"
                );

                $ticket->update([
                    'status'     => 'settled',
                    'winner_id'  => $winner->id,
                    'settled_at' => now(),
                ]);

                $stats['settled']++;
                $stats['total_paid']       += $ticket->net_payout;
                $stats['total_commission'] += $ticket->commission_amount;
            }

            // Tickets OPEN restants → annulés et remboursés
            $openTickets = BetTicket::where('market_id', $market->id)
                ->where('status', 'open')
                ->with('creator')
                ->get();

            foreach ($openTickets as $ticket) {
                $this->refundSingleTicket($ticket, 'Marché réglé — ticket non apparié');
            }

            $market->update(['status' => 'settled']);

            Log::info("Clash Bet P2P: Marché #{$market->id} réglé — {$stats['settled']} tickets, {$stats['total_paid']} FCFA distribués, {$stats['total_commission']} FCFA de commission");

            return $stats;
        });
    }

    /**
     * Règlement automatique basé sur l'évaluation AST Rule Engine du match.
     */
    public function settleMarketAuto(BetMarket $market): array
    {
        return DB::transaction(function () use ($market) {
            $market = BetMarket::lockForUpdate()->with('match')->findOrFail($market->id);

            if ($market->status === 'settled') {
                throw new \Exception('Ce marché a déjà été réglé.');
            }

            if (!$market->rule_definition) {
                throw new \Exception('Ce marché ne possède pas de définition de règle AST.');
            }

            // 1. Évaluer la règle
            $evalResult  = $this->evaluatorService->evaluateMatch($market->rule_definition, $market->match);
            $winningSide = $evalResult['winning_side']; // 'YES' ou 'NO'

            // Enregistrer le snapshot et la position gagnante
            $market->update([
                'status'              => 'closed',
                'winning_side'        => $winningSide,
                'evaluation_snapshot' => $evalResult['snapshot'],
            ]);

            $stats = ['settled' => 0, 'total_paid' => 0, 'total_commission' => 0];

            // 2. Traiter les tickets matchés / locked
            $tickets = BetTicket::lockForUpdate()
                ->where('market_id', $market->id)
                ->whereIn('status', ['matched', 'locked'])
                ->with(['creator', 'taker'])
                ->get();

            foreach ($tickets as $ticket) {
                // Déterminer qui a la position gagnante
                // Le créateur a $ticket->side (ex: 'YES'). Si winningSide == $ticket->side => créateur a gagné, sinon taker.
                $creatorWon = strtoupper($ticket->side ?? 'YES') === $winningSide;
                $winner     = $creatorWon ? $ticket->creator : $ticket->taker;
                $loser      = $creatorWon ? $ticket->taker   : $ticket->creator;

                if (!$winner || !$loser) {
                    $this->refundSingleTicket($ticket, 'Marché réglé — ticket incomplet');
                    continue;
                }

                // Créditer 100% du pot au gagnant (mise débloquée + gain net)
                $winnerWallet = $winner->getOrCreateWallet();
                $winnerWallet->settleTicketWin(
                    $ticket->amount,
                    $ticket->net_payout,
                    "Gain ticket #{$ticket->ticket_number} ({$market->title})"
                );

                // Déverrouiller la mise du perdant
                $loserWallet = $loser->getOrCreateWallet();
                $loserWallet->settleTicketLoss(
                    $ticket->amount,
                    "Mise perdue ticket #{$ticket->ticket_number}"
                );

                $ticket->update([
                    'status'     => 'settled',
                    'winner_id'  => $winner->id,
                    'settled_at' => now(),
                ]);

                $stats['settled']++;
                $stats['total_paid']       += $ticket->net_payout;
                $stats['total_commission'] += $ticket->commission_amount;
            }

            // 3. Tickets OPEN restants → annulés et remboursés
            $openTickets = BetTicket::where('market_id', $market->id)
                ->where('status', 'open')
                ->with('creator')
                ->get();

            foreach ($openTickets as $ticket) {
                $this->refundSingleTicket($ticket, 'Marché réglé — ticket non apparié');
            }

            $market->update(['status' => 'settled']);

            Log::info("Clash Bet P2P (Auto Rule Engine): Marché #{$market->id} réglé [Gagnant: {$winningSide}] — {$stats['settled']} tickets réglés.");

            return array_merge($stats, ['winning_side' => $winningSide]);
        });
    }

    /**
     * Règle ou départage un TICKET INDIVIDUEL manuellement par l'administrateur.
     * $outcome can be 'creator', 'taker', or 'refund'
     */
    public function settleSingleTicket(BetTicket $ticket, string $outcome, ?User $admin = null, ?string $reason = null): array
    {
        return DB::transaction(function () use ($ticket, $outcome, $admin, $reason) {
            $ticket = BetTicket::lockForUpdate()->with(['creator', 'taker'])->findOrFail($ticket->id);

            if ($ticket->status === 'settled') {
                throw new \Exception("Ce ticket #{$ticket->ticket_number} est déjà réglé.");
            }

            if ($outcome === 'refund' || $outcome === 'draw') {
                $this->refundSingleTicket($ticket, $reason ?? "Départage admin : Égalité / Annulation");
                return [
                    'success' => true,
                    'status' => 'refunded',
                    'message' => "Ticket #{$ticket->ticket_number} annulé et remboursé aux deux joueurs.",
                ];
            }

            $creatorWon = ($outcome === 'creator' || (is_numeric($outcome) && (int)$outcome === $ticket->creator_id));
            $winner = $creatorWon ? $ticket->creator : $ticket->taker;
            $loser  = $creatorWon ? $ticket->taker   : $ticket->creator;

            if (!$winner) {
                throw new \Exception("Le gagnant sélectionné n'est pas valide pour ce ticket.");
            }

            // Créditer le gagnant
            $winnerWallet = $winner->getOrCreateWallet();
            $winnerWallet->settleTicketWin(
                $ticket->amount,
                $ticket->net_payout,
                "Gain départage admin ticket #{$ticket->ticket_number}"
            );

            // Déverrouiller la mise du perdant s'il existe
            if ($loser) {
                $loserWallet = $loser->getOrCreateWallet();
                $loserWallet->settleTicketLoss(
                    $ticket->amount,
                    "Mise perdue ticket #{$ticket->ticket_number}"
                );
            }

            $ticket->update([
                'status'     => 'settled',
                'winner_id'  => $winner->id,
                'settled_at' => now(),
            ]);

            \App\Models\ClashBetAudit::create([
                'admin_id'   => $admin?->id ?? \Illuminate\Support\Facades\Auth::id(),
                'event_type' => 'TICKET_SETTLED_MANUALLY',
                'market_id'  => $ticket->market_id,
                'payload'    => [
                    'ticket_id'     => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'outcome'       => $outcome,
                    'winner_id'     => $winner->id,
                    'winner_name'   => $winner->name,
                    'reason'        => $reason,
                ],
            ]);

            Log::info("Clash Bet P2P: Ticket #{$ticket->ticket_number} tranché manuellement par Admin #{$admin?->id} — Gagnant User #{$winner->id} ({$winner->name})");

            return [
                'success' => true,
                'status' => 'settled',
                'winner_id' => $winner->id,
                'winner_name' => $winner->name,
                'message' => "Ticket #{$ticket->ticket_number} tranché en faveur de {$winner->name}.",
            ];
        });
    }


    // ─── Annulation d'un Marché ───────────────────────────────────────────────

    /**
     * Annule un marché et rembourse intégralement tous les tickets.
     */
    public function cancelMarket(BetMarket $market, string $reason): int
    {
        return DB::transaction(function () use ($market, $reason) {
            $tickets = BetTicket::where('market_id', $market->id)
                ->whereIn('status', ['open', 'matched', 'locked'])
                ->with(['creator', 'taker'])
                ->get();

            $count = 0;
            foreach ($tickets as $ticket) {
                $this->refundSingleTicket($ticket, "Marché annulé: {$reason}");
                $count++;
            }

            $market->update([
                'status'           => 'cancelled',
                'cancelled_reason' => $reason,
            ]);

            Log::info("Clash Bet P2P: Marché #{$market->id} annulé — {$count} tickets remboursés");

            return $count;
        });
    }

    // ─── Helpers Internes ─────────────────────────────────────────────────────

    private function refundSingleTicket(BetTicket $ticket, string $reason): void
    {
        // Rembourser le créateur si sa mise est bloquée
        if (in_array($ticket->status, ['open', 'matched', 'locked'])) {
            $ticket->creator?->getOrCreateWallet()?->unlockTicket(
                $ticket->amount,
                'bet_refund',
                "Remboursement #{$ticket->ticket_number}: {$reason}",
                (string) $ticket->id
            );

            // Rembourser le preneur si le ticket était matched/locked
            if ($ticket->taker_id && in_array($ticket->status, ['matched', 'locked'])) {
                $ticket->taker?->getOrCreateWallet()?->unlockTicket(
                    $ticket->amount,
                    'bet_refund',
                    "Remboursement #{$ticket->ticket_number}: {$reason}",
                    (string) $ticket->id
                );
            }
        }

        $ticket->update(['status' => 'refunded', 'settled_at' => now()]);
    }

    private function validateMarketOpen(BetMarket $market): void
    {
        if ($market->status !== 'open') {
            throw new \Exception('Ce marché n\'est plus ouvert aux paris.');
        }

        if ($market->betting_closes_at && now()->greaterThanOrEqualTo($market->betting_closes_at)) {
            throw new \Exception('Les paris sont fermés pour ce marché.');
        }
    }

    private function validateAmount(int $amount): void
    {
        $min = AppSetting::clashBetMinAmount();
        $max = AppSetting::clashBetMaxAmount();

        if ($amount < $min) {
            throw new \Exception("Mise minimale : {$min} FCFA.");
        }
        if ($amount > $max) {
            throw new \Exception("Mise maximale : {$max} FCFA.");
        }
    }

    private function validateBalance(User $user, int $amount): void
    {
        $wallet = $user->getOrCreateWallet();
        if ($wallet->balance < $amount) {
            throw new \Exception("Solde insuffisant. Disponible : {$wallet->balance} FCFA.");
        }
    }

    /**
     * Score de risque antifraude simplifié.
     * Regarde la fréquence de matching répétitif entre les deux mêmes utilisateurs.
     */
    private function computeRiskScore(int $creatorId, int $takerId): int
    {
        $recentMatchings = BetTicket::where('creator_id', $creatorId)
            ->where('taker_id', $takerId)
            ->whereIn('status', ['matched', 'locked', 'settled'])
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($recentMatchings >= 10) return 80;
        if ($recentMatchings >= 5)  return 50;
        if ($recentMatchings >= 3)  return 30;
        return 0;
    }
}
