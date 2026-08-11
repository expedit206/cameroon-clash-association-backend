<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Models\TournamentMatch;
use App\Models\Withdrawal;
use App\Services\ClashBetOddsService;
use App\Services\ClashBetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminBetController extends Controller
{
    public function __construct(
        private readonly ClashBetService     $betService,
        private readonly ClashBetOddsService $oddsService
    ) {}

    /**
     * GET /admin/clash-bet/stats
     * Statistiques globales du module Clash Bet.
     */
    public function stats()
    {
        return response()->json([
            'total_markets'      => BetMarket::count(),
            'open_markets'       => BetMarket::open()->count(),
            'total_bets'         => Bet::count(),
            'total_pool_volume'  => BetMarket::sum('total_pool'),
            'total_won'          => Bet::where('status', 'won')->sum('actual_payout'),
            'pending_withdrawals'=> Withdrawal::pending()->count(),
            'pending_withdrawal_volume' => Withdrawal::pending()->sum('amount'),
            'total_fees_collected' => Withdrawal::where('status', 'completed')->sum('fee'),
        ]);
    }

    /**
     * GET /admin/clash-bet/markets
     * Liste tous les marchés.
     */
    public function markets(Request $request)
    {
        $markets = BetMarket::with(['match.clanHome', 'match.clanAway', 'options'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        $markets->getCollection()->transform(function ($market) {
            $allOdds = $this->oddsService->computeAllOdds($market);
            return [
                'id'          => $market->id,
                'status'      => $market->status,
                'total_pool'  => $market->total_pool,
                'bets_count'  => $market->bets()->count(),
                'betting_closes_at' => $market->betting_closes_at?->toISOString(),
                'match' => [
                    'id'   => $market->match?->id,
                    'home' => $market->match?->clanHome?->name,
                    'away' => $market->match?->clanAway?->name,
                    'status' => $market->match?->status,
                    'scheduled_at' => $market->match?->scheduled_at?->toISOString(),
                ],
                'options' => $market->options->map(fn($opt) => [
                    'id'           => $opt->id,
                    'label'        => $opt->label,
                    'current_pool' => $opt->current_pool,
                    'current_odds' => $allOdds[$opt->id] ?? 2.0,
                ]),
            ];
        });

        return response()->json($markets);
    }

    /**
     * POST /admin/clash-bet/markets
     * Créer un marché de prédiction sur un match CCA.
     */
    public function createMarket(Request $request)
    {
        $request->validate([
            'match_id'        => 'required|exists:tournament_matches,id',
            'liquidity_weight'=> 'nullable|integer|min:10000',
            'betting_closes_at' => 'nullable|date|after:now',
        ]);

        $match = TournamentMatch::with(['clanHome', 'clanAway'])->findOrFail($request->match_id);

        // Vérifier qu'il n'y a pas déjà un marché ouvert pour ce match
        $exists = BetMarket::where('match_id', $match->id)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Un marché existe déjà pour ce match.',
            ], 422);
        }

        $market = BetMarket::create([
            'match_id'          => $match->id,
            'status'            => 'open',
            'liquidity_weight'  => $request->liquidity_weight ?? 100000,
            'total_pool'        => 0,
            'betting_closes_at' => $request->betting_closes_at,
        ]);

        // Créer les deux options (Clan Home / Clan Away)
        BetOption::create([
            'market_id' => $market->id,
            'label'     => $match->clanHome?->name ?? 'Équipe A',
            'clan_id'   => $match->clan_home_id,
        ]);

        BetOption::create([
            'market_id' => $market->id,
            'label'     => $match->clanAway?->name ?? 'Équipe B',
            'clan_id'   => $match->clan_away_id,
        ]);

        $market->load('options');

        return response()->json([
            'success' => true,
            'market'  => $market,
            'message' => "Marché créé pour le match #{$match->id}.",
        ], 201);
    }

    /**
     * PUT /admin/clash-bet/markets/{market}/status
     * Mettre à jour le statut d'un marché (suspended/closed).
     */
    public function updateStatus(Request $request, BetMarket $market)
    {
        $request->validate([
            'status' => 'required|in:open,suspended,closed',
            'reason' => 'nullable|string|max:255',
        ]);

        $market->update([
            'status'           => $request->status,
            'cancelled_reason' => $request->reason,
        ]);

        return response()->json(['success' => true, 'market' => $market]);
    }

    /**
     * POST /admin/clash-bet/markets/{market}/settle
     * Valider le résultat et distribuer les gains.
     */
    public function settle(Request $request, BetMarket $market)
    {
        $request->validate([
            'winning_option_id' => 'required|exists:bet_options,id',
        ]);

        // Fermer le marché avant règlement
        if ($market->status === 'open' || $market->status === 'suspended') {
            $market->update(['status' => 'closed']);
        }

        if (!in_array($market->status, ['closed', 'settled'])) {
            return response()->json([
                'success' => false,
                'message' => "Le marché doit être fermé avant le règlement (statut actuel: {$market->status}).",
            ], 422);
        }

        if ($market->status === 'settled') {
            return response()->json(['success' => false, 'message' => 'Ce marché a déjà été réglé.'], 422);
        }

        try {
            $stats = $this->betService->settleMarket($market, $request->winning_option_id);

            return response()->json([
                'success'     => true,
                'winners'     => $stats['winners'],
                'losers'      => $stats['losers'],
                'total_paid'  => $stats['total_paid'],
                'message'     => "Marché réglé : {$stats['winners']} gagnants, {$stats['total_paid']} FCFA distribués.",
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/clash-bet/markets/{market}/cancel
     * Annuler un marché et rembourser tous les paris.
     */
    public function cancel(Request $request, BetMarket $market)
    {
        $request->validate(['reason' => 'required|string|max:255']);

        try {
            $this->betService->cancelMarket($market, $request->reason);
            return response()->json(['success' => true, 'message' => 'Marché annulé et paris remboursés.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /admin/clash-bet/withdrawals
     * Liste les demandes de retrait.
     */
    public function withdrawals(Request $request)
    {
        $withdrawals = Withdrawal::with('user')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25);

        return response()->json($withdrawals);
    }

    /**
     * PUT /admin/clash-bet/withdrawals/{withdrawal}/process
     * Valider ou rejeter un retrait.
     */
    public function processWithdrawal(Request $request, Withdrawal $withdrawal)
    {
        $request->validate([
            'action'     => 'required|in:approve,reject',
            'admin_note' => 'nullable|string|max:500',
        ]);

        if ($withdrawal->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Ce retrait n\'est plus en attente.'], 422);
        }

        if ($request->action === 'approve') {
            $withdrawal->update([
                'status'       => 'completed',
                'admin_note'   => $request->admin_note,
                'processed_by' => Auth::id(),
                'processed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Retrait de {$withdrawal->net_amount} FCFA approuvé pour {$withdrawal->user->name}.",
            ]);
        } else {
            // Remboursement si rejeté
            $user   = $withdrawal->user;
            $wallet = $user->getOrCreateWallet();

            \Illuminate\Support\Facades\DB::transaction(function () use ($withdrawal, $wallet, $request) {
                $wallet->credit(
                    $withdrawal->amount,
                    'bet_refund',
                    "Remboursement retrait rejeté — Note: {$request->admin_note}",
                    (string) $withdrawal->id,
                    'App\\Models\\Withdrawal'
                );

                $withdrawal->update([
                    'status'       => 'failed',
                    'admin_note'   => $request->admin_note,
                    'processed_by' => Auth::id(),
                    'processed_at' => now(),
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => "Retrait rejeté. {$withdrawal->amount} FCFA remboursés sur le wallet de {$withdrawal->user->name}.",
            ]);
        }
    }

    /**
     * GET /admin/clash-bet/available-matches
     * Liste les matchs sans marché de pari (pour créer un marché).
     */
    public function availableMatches()
    {
        $matchesWithMarkets = BetMarket::whereNotIn('status', ['cancelled'])
            ->pluck('match_id');

        $matches = TournamentMatch::with(['clanHome', 'clanAway'])
            ->whereNotIn('id', $matchesWithMarkets)
            ->whereIn('status', ['upcoming', 'scheduled', 'pending'])
            ->get()
            ->map(fn($m) => [
                'id'          => $m->id,
                'status'      => $m->status,
                'scheduled_at'=> $m->scheduled_at?->toISOString(),
                'clan_home'   => $m->clanHome?->name,
                'clan_away'   => $m->clanAway?->name,
            ]);

        return response()->json($matches);
    }
}
