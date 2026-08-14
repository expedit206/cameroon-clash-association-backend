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
            ->when($request->status && $request->status !== 'all', fn($q) => $q->where('status', $request->status))
            ->when($request->category && $request->category !== 'all', fn($q) => $q->where('category', $request->category))
            ->orderByRaw("CASE WHEN status = 'open' THEN 1 WHEN status = 'suspended' THEN 2 WHEN status = 'settled' THEN 3 WHEN status = 'cancelled' THEN 4 ELSE 5 END ASC")
            ->latest()
            ->paginate(10);

        $markets->getCollection()->transform(function ($market) {
            return [
                'id'                => $market->id,
                'title'             => $market->title,
                'description'       => $market->description,
                'category'          => $market->category,
                'rule_definition'   => $market->rule_definition,
                'rule_version'      => $market->rule_version,
                'winning_side'      => $market->winning_side,
                'winning_option_id' => $market->winning_option_id,
                'status'            => $market->status,
                'total_pool'        => $market->total_pool,
                'betting_closes_at' => $market->betting_closes_at?->toISOString(),
                'cancelled_reason'  => $market->cancelled_reason,
                'match' => [
                    'id'           => $market->match?->id,
                    'clan_home'    => $market->match?->clanHome?->name ?? 'Clan Hôte',
                    'clan_away'    => $market->match?->clanAway?->name ?? 'Clan Invité',
                    'home'         => $market->match?->clanHome?->name ?? 'Clan Hôte',
                    'away'         => $market->match?->clanAway?->name ?? 'Clan Invité',
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

    /**
     * PUT /admin/clash-bet/markets/{market}/live-toggle
     */
    public function toggleLiveBetting(Request $request, BetMarket $market)
    {
        $request->validate([
            'allow_during_match' => 'nullable|boolean',
        ]);

        $allow = $request->has('allow_during_match')
            ? $request->boolean('allow_during_match')
            : !$market->allow_during_match;

        $market->update([
            'allow_during_match' => $allow,
        ]);

        return response()->json([
            'success'            => true,
            'market'             => $market,
            'allow_during_match' => $allow,
            'message'            => $allow ? 'Marché maintenu ouvert pendant le match.' : 'Marché avec fermeture standard.',
        ]);
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

    /**
     * DELETE /admin/clash-bet/markets/{market}
     * Supprimer définitivement un marché et rembourser ses tickets s'il y en a.
     */
    public function destroyMarket(BetMarket $market)
    {
        try {
            $openTicketsCount = BetTicket::where('market_id', $market->id)
                ->whereNotIn('status', ['settled', 'cancelled'])
                ->count();

            if ($openTicketsCount > 0) {
                $this->ticketService->cancelMarket($market, "Suppression du marché par un administrateur.");
            }

            BetOption::where('market_id', $market->id)->delete();
            BetTicket::where('market_id', $market->id)->delete();

            $marketId = $market->id;
            $marketTitle = $market->title;
            $market->delete();

            ClashBetAudit::create([
                'admin_id'   => Auth::id(),
                'event_type' => 'MARKET_DELETED',
                'market_id'  => $marketId,
                'payload'    => [
                    'market_id'        => $marketId,
                    'title'            => $marketTitle,
                    'refunded_tickets' => $openTicketsCount,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => "Marché #{$marketId} supprimé définitivement. {$openTicketsCount} ticket(s) remboursé(s).",
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /admin/clash-bet/matches/{match}/markets
     * Supprime tous les marchés P2P associés à un match donné.
     */
    public function destroyMatchMarkets(TournamentMatch $match)
    {
        try {
            $markets = BetMarket::where('match_id', $match->id)->get();
            $count = $markets->count();
            $totalRefunded = 0;

            foreach ($markets as $market) {
                $openTicketsCount = BetTicket::where('market_id', $market->id)
                    ->whereNotIn('status', ['settled', 'cancelled'])
                    ->count();

                if ($openTicketsCount > 0) {
                    $this->ticketService->cancelMarket($market, "Suppression globale des marchés du match #{$match->id}.");
                    $totalRefunded += $openTicketsCount;
                }

                BetOption::where('market_id', $market->id)->delete();
                BetTicket::where('market_id', $market->id)->delete();
                $market->delete();
            }

            ClashBetAudit::create([
                'admin_id'   => Auth::id(),
                'event_type' => 'MATCH_MARKETS_DELETED',
                'market_id'  => null,
                'payload'    => [
                    'match_id'         => $match->id,
                    'deleted_count'    => $count,
                    'refunded_tickets' => $totalRefunded,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$count} marché(s) lié(s) au match #{$match->id} supprimé(s). {$totalRefunded} ticket(s) remboursé(s).",
            ]);
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
            'side'                  => $t->side,
            'market_id'             => $t->market_id,
            'market_title'          => $t->market?->title,
            'amount'                => $t->amount,
            'gross_payout'          => $t->gross_payout,
            'commission_amount'     => $t->commission_amount,
            'net_payout'            => $t->net_payout,
            'risk_score'            => $t->risk_score,
            'review_required'       => $t->review_required,
            'creator_id'            => $t->creator_id,
            'taker_id'              => $t->taker_id,
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

    /**
     * POST /admin/clash-bet/tickets/{ticket}/settle
     * Tranche manuellement le résultat d'un ticket (créateur gagne, preneur gagne, ou remboursement/égalité).
     */
    public function settleTicket(Request $request, BetTicket $ticket)
    {
        $request->validate([
            'outcome' => 'required|string|in:creator,taker,refund,draw',
            'reason'  => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->ticketService->settleSingleTicket(
                $ticket,
                $request->outcome,
                Auth::user(),
                $request->reason
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
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
            'clash_bet_public_enabled'           => AppSetting::clashBetPublicEnabled(),
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
            'clash_bet_public_enabled'            => 'nullable|boolean',
        ]);

        foreach ($request->only([
            'clash_bet_commission_percentage',
            'clash_bet_close_offset_minutes',
            'clash_bet_min_amount',
            'clash_bet_max_amount',
            'clash_bet_withdrawal_fee_percentage',
            'clash_bet_public_enabled',
        ]) as $key => $value) {
            if (!is_null($value)) {
                $valStr = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                AppSetting::set($key, $valStr);
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

                // Duels H2H (niveaux de jeu)
                $duels = \App\Models\Duel::with(['playerHome', 'playerAway'])
                    ->where('match_id', $m->id)
                    ->orderBy('hdv_level')
                    ->get()
                    ->map(fn($d) => [
                        'id'                 => $d->id,
                        'hdv_level'          => $d->hdv_level,
                        'player_home_id'     => $d->player_home_id,
                        'player_home_name'   => $d->playerHome?->name ?? "Joueur HDV{$d->hdv_level} ({$m->clanHome?->name})",
                        'player_away_id'     => $d->player_away_id,
                        'player_away_name'   => $d->playerAway?->name ?? "Joueur HDV{$d->hdv_level} ({$m->clanAway?->name})",
                    ]);

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
                    'duels'             => $duels,
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
            'category'          => 'required|in:team,player,comparison,duel,advanced',
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
