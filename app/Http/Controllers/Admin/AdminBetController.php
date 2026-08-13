<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Models\BetTicket;
use App\Models\TournamentMatch;
use App\Models\Withdrawal;
use App\Models\ClashBetAudit;
use App\Services\ClashBetTicketService;
use App\Services\ClashBet\MarketBuilderService;
use App\Services\ClashBet\RuleEvaluatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminBetController extends Controller
{
    public function __construct(
        private readonly ClashBetTicketService $ticketService,
        private readonly MarketBuilderService $marketBuilderService,
        private readonly RuleEvaluatorService $ruleEvaluatorService
    ) {}

    // ─── Statistiques Globales ────────────────────────────────────────────────

    /**
     * GET /admin/clash-bet/stats
     */
    public function stats()
    {
        return response()->json([
            'total_markets'          => BetMarket::count(),
            'open_markets'           => BetMarket::where('status', 'open')->count(),
            'total_tickets'          => BetTicket::count(),
            'open_tickets'           => BetTicket::where('status', 'open')->count(),
            'matched_tickets'        => BetTicket::where('status', 'matched')->count(),
            'settled_tickets'        => BetTicket::where('status', 'settled')->count(),
            'total_volume_locked'    => BetTicket::whereIn('status', ['open', 'matched', 'locked'])->sum('amount'),
            'total_commission'       => Withdrawal::where('status', 'completed')->sum('fee'),
            'pending_withdrawals'    => Withdrawal::where('status', 'pending')->count(),
            'review_flags'           => BetTicket::where('review_required', true)->where('status', '!=', 'settled')->count(),
        ]);
    }

    // ─── Gestion des Marchés ──────────────────────────────────────────────────

    /**
     * GET /admin/clash-bet/markets
     */
    public function markets(Request $request)
    {
        $markets = BetMarket::with(['match.clanHome', 'match.clanAway', 'options'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        $markets->getCollection()->transform(function ($market) {
            return [
                'id'                => $market->id,
                'status'            => $market->status,
                'betting_closes_at' => $market->betting_closes_at?->toISOString(),
                'cancelled_reason'  => $market->cancelled_reason,
                'match' => [
                    'id'           => $market->match?->id,
                    'home'         => $market->match?->clanHome?->name,
                    'away'         => $market->match?->clanAway?->name,
                    'status'       => $market->match?->status,
                    'scheduled_at' => $market->match?->scheduled_at?->toISOString(),
                ],
                'options' => $market->options->map(fn($opt) => [
                    'id'    => $opt->id,
                    'label' => $opt->label,
                ]),
                'open_tickets'    => BetTicket::where('market_id', $market->id)->where('status', 'open')->count(),
                'matched_tickets' => BetTicket::where('market_id', $market->id)->where('status', 'matched')->count(),
                'settled_tickets' => BetTicket::where('market_id', $market->id)->where('status', 'settled')->count(),
                'total_volume'    => BetTicket::where('market_id', $market->id)->whereIn('status', ['matched', 'locked', 'settled'])->sum('amount') * 2,
            ];
        });

        return response()->json($markets);
    }

    /**
     * POST /admin/clash-bet/markets
     */
    public function createMarket(Request $request)
    {
        $request->validate([
            'match_id'          => 'required|exists:tournament_matches,id',
            'betting_closes_at' => 'nullable|date|after:now',
        ]);

        $match = TournamentMatch::with(['clanHome', 'clanAway'])->findOrFail($request->match_id);

        $exists = BetMarket::where('match_id', $match->id)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Un marché existe déjà pour ce match.'], 422);
        }

        $market = BetMarket::create([
            'match_id'          => $match->id,
            'status'            => 'open',
            'total_pool'        => 0,
            'betting_closes_at' => $request->betting_closes_at,
        ]);

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

        return response()->json(['success' => true, 'market' => $market, 'message' => "Marché P2P créé pour le match #{$match->id}."], 201);
    }

    /**
     * PUT /admin/clash-bet/markets/{market}/status
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

    // ─── Règlement & Annulation ───────────────────────────────────────────────

    /**
     * POST /admin/clash-bet/markets/{market}/settle
     * Déclarer le résultat officiel et distribuer les gains.
     */
    public function settle(Request $request, BetMarket $market)
    {
        $request->validate([
            'winning_option_id' => 'required|exists:bet_options,id',
        ]);

        if ($market->status === 'settled') {
            return response()->json(['success' => false, 'message' => 'Ce marché a déjà été réglé.'], 422);
        }

        try {
            $stats = $this->ticketService->settleMarket($market, $request->winning_option_id);

            return response()->json([
                'success'          => true,
                'settled'          => $stats['settled'],
                'total_paid'       => $stats['total_paid'],
                'total_commission' => $stats['total_commission'],
                'message'          => "Marché réglé : {$stats['settled']} tickets, {$stats['total_paid']} FCFA distribués, {$stats['total_commission']} FCFA de commission.",
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/clash-bet/markets/{market}/cancel
     * Annuler un marché et rembourser 100% sans frais.
     */
    public function cancel(Request $request, BetMarket $market)
    {
        $request->validate(['reason' => 'required|string|max:255']);

        try {
            $count = $this->ticketService->cancelMarket($market, $request->reason);
            return response()->json(['success' => true, 'refunded' => $count, 'message' => "{$count} ticket(s) remboursé(s) intégralement."]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─── Gestion des Tickets ──────────────────────────────────────────────────

    /**
     * GET /admin/clash-bet/tickets
     */
    public function tickets(Request $request)
    {
        $tickets = BetTicket::with(['market.match.clanHome', 'market.match.clanAway', 'creator', 'taker', 'creatorOption', 'takerOption', 'winner'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->market_id, fn($q) => $q->where('market_id', $request->market_id))
            ->when($request->review_required, fn($q) => $q->where('review_required', true))
            ->latest()
            ->paginate(25);

        $tickets->getCollection()->transform(fn($t) => [
            'id'                    => $t->id,
            'ticket_number'         => $t->ticket_number,
            'status'                => $t->status,
            'amount'                => $t->amount,
            'gross_payout'          => $t->gross_payout,
            'commission_amount'     => $t->commission_amount,
            'net_payout'            => $t->net_payout,
            'risk_score'            => $t->risk_score,
            'review_required'       => $t->review_required,
            'creator'               => ['id' => $t->creator?->id, 'name' => $t->creator?->name],
            'taker'                 => $t->taker_id ? ['id' => $t->taker?->id, 'name' => $t->taker?->name] : null,
            'creator_option'        => $t->creatorOption?->label,
            'taker_option'          => $t->takerOption?->label,
            'winner'                => $t->winner_id ? ['id' => $t->winner?->id, 'name' => $t->winner?->name] : null,
            'match'                 => $t->market?->match ? [
                'home' => $t->market->match->clanHome?->name,
                'away' => $t->market->match->clanAway?->name,
            ] : null,
            'matched_at'            => $t->matched_at?->toISOString(),
            'settled_at'            => $t->settled_at?->toISOString(),
            'created_at'            => $t->created_at->toISOString(),
        ]);

        return response()->json($tickets);
    }

    // ─── Configuration ────────────────────────────────────────────────────────

    /**
     * GET /admin/clash-bet/settings
     */
    public function settings()
    {
        return response()->json([
            'clash_bet_commission_percentage'    => AppSetting::clashBetCommission(),
            'clash_bet_close_offset_minutes'     => AppSetting::clashBetCloseOffset(),
            'clash_bet_min_amount'               => AppSetting::clashBetMinAmount(),
            'clash_bet_max_amount'               => AppSetting::clashBetMaxAmount(),
            'clash_bet_fixed_odds'               => AppSetting::clashBetFixedOdds(),
            'clash_bet_withdrawal_fee_percentage'=> AppSetting::clashBetWithdrawalFee(),
        ]);
    }

    /**
     * PUT /admin/clash-bet/settings
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'clash_bet_commission_percentage'     => 'nullable|numeric|min:0|max:50',
            'clash_bet_close_offset_minutes'      => 'nullable|integer|min:0|max:60',
            'clash_bet_min_amount'                => 'nullable|integer|min:100',
            'clash_bet_max_amount'                => 'nullable|integer|max:1000000',
            'clash_bet_withdrawal_fee_percentage' => 'nullable|numeric|min:0|max:50',
        ]);

        foreach ($request->only([
            'clash_bet_commission_percentage',
            'clash_bet_close_offset_minutes',
            'clash_bet_min_amount',
            'clash_bet_max_amount',
            'clash_bet_withdrawal_fee_percentage',
        ]) as $key => $value) {
            if (!is_null($value)) {
                AppSetting::set($key, (string) $value);
            }
        }

        return response()->json(['success' => true, 'message' => 'Configuration mise à jour.']);
    }

    // ─── Matchs Disponibles & Retraits ───────────────────────────────────────

    /**
     * GET /admin/clash-bet/available-matches
     */
    public function availableMatches()
    {
        $matchesWithMarkets = BetMarket::whereNotIn('status', ['cancelled'])->pluck('match_id');

        // ->whereNotIn('id', $matchesWithMarkets)
        $matches = TournamentMatch::with(['clanHome', 'clanAway'])
            ->whereIn('status', ['upcoming', 'scheduled', 'pending'])
            ->get()
            ->map(function ($m) {
                $homeReg = \App\Models\ClanRegistration::where('clan_id', $m->clan_home_id)
                    ->where('competition_id', $m->competition_id)
                    ->first();
                $homePlayers = $homeReg
                    ? \App\Models\RegistrationPlayer::with('user')->where('clan_registration_id', $homeReg->id)->get()->map(fn($p) => [
                        'id'            => $p->user?->id,
                        'name'          => $p->user?->name,
                        'tag_coc'       => $p->user?->tag_coc,
                        'is_substitute' => $p->is_substitute,
                        'role_label'    => $p->is_substitute ? 'Remplaçant' : 'Titulaire',
                    ])
                    : collect([]);

                $awayReg = \App\Models\ClanRegistration::where('clan_id', $m->clan_away_id)
                    ->where('competition_id', $m->competition_id)
                    ->first();
                $awayPlayers = $awayReg
                    ? \App\Models\RegistrationPlayer::with('user')->where('clan_registration_id', $awayReg->id)->get()->map(fn($p) => [
                        'id'            => $p->user?->id,
                        'name'          => $p->user?->name,
                        'tag_coc'       => $p->user?->tag_coc,
                        'is_substitute' => $p->is_substitute,
                        'role_label'    => $p->is_substitute ? 'Remplaçant' : 'Titulaire',
                    ])
                    : collect([]);

                return [
                    'id'                => $m->id,
                    'status'            => $m->status,
                    'competition_id'    => $m->competition_id,
                    'scheduled_at'      => $m->scheduled_at?->toISOString(),
                    'clan_home'         => $m->clanHome?->name ?? 'Clan A',
                    'clan_home_id'      => $m->clan_home_id,
                    'clan_home_badge'   => $m->clanHome?->badge_url,
                    'clan_home_players' => $homePlayers,
                    'clan_away'         => $m->clanAway?->name ?? 'Clan B',
                    'clan_away_id'      => $m->clan_away_id,
                    'clan_away_badge'   => $m->clanAway?->badge_url,
                    'clan_away_players' => $awayPlayers,
                ];
            });

        return response()->json($matches);
    }

    /**
     * GET /admin/clash-bet/withdrawals
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
            return response()->json(['success' => true, 'message' => "Retrait approuvé pour {$withdrawal->user->name}."]);
        } else {
            $user   = $withdrawal->user;
            $wallet = $user->getOrCreateWallet();

            \Illuminate\Support\Facades\DB::transaction(function () use ($withdrawal, $wallet, $request) {
                $wallet->credit(
                    $withdrawal->amount,
                    'bet_refund',
                    "Remboursement retrait rejeté — {$request->admin_note}",
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

            return response()->json(['success' => true, 'message' => "Retrait rejeté. {$withdrawal->amount} FCFA remboursés."]);
        }
    }

    /**
     * POST /admin/clash-bet/markets/builder
     * Création d'un marché avec règle AST JSON.
     */
    public function createMarketBuilder(Request $request)
    {
        $request->validate([
            'match_id'          => 'required|exists:tournament_matches,id',
            'category'          => 'required|in:team,player,comparison,advanced',
            'rule_definition'   => 'required|array',
            'title'             => 'nullable|string|max:255',
            'description'       => 'nullable|string',
            'betting_closes_at' => 'nullable|date',
        ]);

        try {
            $market = $this->marketBuilderService->createMarket($request->all());
            return response()->json([
                'success' => true,
                'message' => 'Marché créé avec succès avec la règle AST.',
                'market'  => $market->load('match.clanHome', 'match.clanAway'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /admin/clash-bet/markets/simulate
     * Teste une règle AST JSON sur des données simulées.
     */
    public function simulateRule(Request $request)
    {
        $request->validate([
            'rule_definition' => 'required|array',
            'mock_dataset'    => 'required|array',
        ]);

        try {
            $eval = $this->ruleEvaluatorService->evaluateMock(
                $request->rule_definition,
                $request->mock_dataset
            );
            return response()->json([
                'success' => true,
                'eval'    => $eval,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /admin/clash-bet/markets/bulk-generate
     * Génération en lot de marchés standards.
     */
    public function bulkGenerate(Request $request)
    {
        $request->validate([
            'match_id' => 'required|exists:tournament_matches,id',
        ]);

        try {
            $match   = TournamentMatch::findOrFail($request->match_id);
            $created = $this->marketBuilderService->bulkGenerateStandardMarkets($match);
            return response()->json([
                'success' => true,
                'message' => count($created) . " marchés générés automatiquement avec succès.",
                'markets' => $created,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /admin/clash-bet/markets/{market}/settle-auto
     * Règlement automatique d'un marché par Rule Engine.
     */
    public function settleAuto(BetMarket $market)
    {
        try {
            $stats = $this->ticketService->settleMarketAuto($market);

            ClashBetAudit::create([
                'admin_id'   => Auth::id(),
                'event_type' => 'MARKET_SETTLED_AUTO',
                'market_id'  => $market->id,
                'payload'    => $stats,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Marché réglé automatiquement. Position gagnante: {$stats['winning_side']}.",
                'stats'   => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /admin/clash-bet/audits
     */
    public function audits(Request $request)
    {
        $audits = ClashBetAudit::with(['admin', 'market', 'ticket'])
            ->latest()
            ->paginate(30);

        return response()->json($audits);
    }
}
