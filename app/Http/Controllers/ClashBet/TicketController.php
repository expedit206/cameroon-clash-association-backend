<?php

namespace App\Http\Controllers\ClashBet;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Models\BetTicket;
use App\Services\ClashBetTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketController extends Controller
{
    public function __construct(
        private readonly ClashBetTicketService $ticketService
    ) {}

    // ─── Marketplace ─────────────────────────────────────────────────────────

    /**
     * GET /clash-bet/matches
     * Liste des matchs avec marchés ouverts et résumé de tickets disponibles.
     */
    public function matches(Request $request)
    {
        $markets = BetMarket::with(['match.clanHome', 'match.clanAway', 'options'])
            ->where('status', 'open')
            ->latest()
            ->get()
            ->map(fn($market) => $this->formatMarketSummary($market));

        return response()->json($markets);
    }

    /**
     * GET /clash-bet/markets/{market}/tickets
     * Tickets ouverts disponibles sur un marché avec filtre par side (YES/NO).
     */
    public function ticketsForMarket(Request $request, BetMarket $market)
    {
        $sideFilter = $request->query('side'); // YES or NO

        $tickets = BetTicket::where('market_id', $market->id)
            ->where('status', 'open')
            ->when($sideFilter, fn($q) => $q->where('side', strtoupper($sideFilter)))
            ->with(['creator'])
            ->latest()
            ->paginate(20);

        $tickets->getCollection()->transform(fn($t) => $this->formatTicketPublic($t));

        return response()->json($tickets);
    }

    /**
     * GET /clash-bet/markets/{market}
     * Détail d'un marché (infos match + options + config de paris).
     */
    public function showMarket(BetMarket $market)
    {
        $market->load(['match.clanHome', 'match.clanAway', 'options']);

        $openByOption = [];
        foreach ($market->options as $option) {
            $openByOption[$option->id] = BetTicket::where('market_id', $market->id)
                ->where('status', 'open')
                ->where('creator_option_id', $option->id)
                ->count();
        }

        return response()->json([
            'market'         => $this->formatMarketSummary($market),
            'open_by_option' => $openByOption,
            'bet_config'     => [
                'fixed_odds'            => AppSetting::clashBetFixedOdds(),
                'commission_percentage' => AppSetting::clashBetCommission(),
                'min_amount'            => AppSetting::clashBetMinAmount(),
                'max_amount'            => AppSetting::clashBetMaxAmount(),
            ],
        ]);
    }

    // ─── Création d'un Ticket ─────────────────────────────────────────────────

    /**
     * POST /clash-bet/tickets
     * Créer un ticket P2P (YES ou NO).
     */
    public function create(Request $request)
    {
        $request->validate([
            'market_id' => 'required|exists:bet_markets,id',
            'side'      => 'required|in:YES,NO,yes,no',
            'amount'    => 'required|integer|min:100',
        ]);

        $user   = Auth::user();
        $market = BetMarket::findOrFail($request->market_id);

        try {
            $ticket = $this->ticketService->createTicket(
                $user,
                $market,
                strtoupper($request->side),
                (int) $request->amount
            );

            return response()->json([
                'success' => true,
                'ticket'  => $this->formatTicketDetail($ticket->load(['market.match.clanHome', 'market.match.clanAway'])),
                'message' => "Ticket #{$ticket->ticket_number} (Position {$ticket->side}) créé avec succès. Votre mise de {$ticket->amount} FCFA est bloquée.",
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ─── Matching d'un Ticket ─────────────────────────────────────────────────

    /**
     * POST /clash-bet/tickets/{ticket}/match
     * Prendre un ticket P2P (matching atomique).
     */
    public function match(Request $request, BetTicket $ticket)
    {
        $user = Auth::user();

        try {
            $matched = $this->ticketService->matchTicket($user, $ticket);

            return response()->json([
                'success' => true,
                'ticket'  => $this->formatTicketDetail($matched),
                'message' => "Ticket #{$matched->ticket_number} apparié ! Votre mise de {$matched->amount} FCFA est engagée.",
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ─── Annulation d'un Ticket ───────────────────────────────────────────────

    /**
     * POST /clash-bet/tickets/{ticket}/cancel
     * Annuler son propre ticket (uniquement si OPEN).
     */
    public function cancel(Request $request, BetTicket $ticket)
    {
        $user = Auth::user();

        try {
            $this->ticketService->cancelTicket($user, $ticket);

            return response()->json([
                'success' => true,
                'message' => "Ticket #{$ticket->ticket_number} annulé. Vos {$ticket->amount} FCFA ont été remboursés.",
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ─── Mes Tickets ─────────────────────────────────────────────────────────

    /**
     * GET /clash-bet/my-tickets
     * Tickets de l'utilisateur connecté avec filtres par statut.
     */
    public function myTickets(Request $request)
    {
        $status = $request->query('status'); // open|matched|locked|settled|cancelled|refunded

        $query = BetTicket::forUser(Auth::id())
            ->with(['market.match.clanHome', 'market.match.clanAway', 'creatorOption', 'takerOption', 'creator', 'taker', 'winner'])
            ->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $tickets = $query->paginate(20);
        $tickets->getCollection()->transform(fn($t) => $this->formatTicketDetail($t));

        return response()->json($tickets);
    }

    /**
     * GET /clash-bet/tickets/{ticket}
     * Détail d'un ticket.
     */
    public function show(BetTicket $ticket)
    {
        $userId = Auth::id();
        if ($ticket->creator_id !== $userId && $ticket->taker_id !== $userId) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $ticket->load(['market.match.clanHome', 'market.match.clanAway', 'creatorOption', 'takerOption', 'creator', 'taker', 'winner']);
        return response()->json($this->formatTicketDetail($ticket));
    }

    // ─── Formatters ──────────────────────────────────────────────────────────

    private function formatMarketSummary(BetMarket $market): array
    {
        $yesTicketsCount = BetTicket::where('market_id', $market->id)->where('status', 'open')->where('side', 'YES')->count();
        $noTicketsCount  = BetTicket::where('market_id', $market->id)->where('status', 'open')->where('side', 'NO')->count();

        return [
            'id'                => $market->id,
            'title'             => $market->title ?? "Marché #{$market->id}",
            'description'       => $market->description,
            'category'          => $market->category ?? 'team',
            'rule_definition'   => $market->rule_definition,
            'status'            => $market->status,
            'winning_side'      => $market->winning_side,
            'yes_tickets_count' => $yesTicketsCount,
            'no_tickets_count'  => $noTicketsCount,
            'betting_closes_at' => $market->betting_closes_at?->toISOString(),
            'match' => [
                'id'           => $market->match?->id,
                'status'       => $market->match?->status,
                'scheduled_at' => $market->match?->scheduled_at?->toISOString(),
                'clan_home'    => [
                    'id'        => $market->match?->clanHome?->id,
                    'name'      => $market->match?->clanHome?->name,
                    'badge_url' => $market->match?->clanHome?->badge_url ?? null,
                ],
                'clan_away'    => [
                    'id'        => $market->match?->clanAway?->id,
                    'name'      => $market->match?->clanAway?->name,
                    'badge_url' => $market->match?->clanAway?->badge_url ?? null,
                ],
            ],
        ];
    }

    private function formatTicketPublic(BetTicket $ticket): array
    {
        return [
            'id'                    => $ticket->id,
            'ticket_number'         => $ticket->ticket_number,
            'creator_id'            => $ticket->creator_id,
            'side'                  => $ticket->side ?? 'YES',
            'amount'                => $ticket->amount,
            'odds'                  => $ticket->odds,
            'gross_payout'          => $ticket->gross_payout,
            'commission_percentage' => $ticket->commission_percentage,
            'commission_amount'     => $ticket->commission_amount,
            'net_payout'            => $ticket->net_payout,
            'created_at'            => $ticket->created_at->toISOString(),
        ];
    }

    private function formatTicketDetail(BetTicket $ticket): array
    {
        $userId    = Auth::id();
        $isCreator = $ticket->creator_id === $userId;
        $mySide    = $isCreator ? ($ticket->side ?? 'YES') : (($ticket->side ?? 'YES') === 'YES' ? 'NO' : 'YES');

        return [
            'id'                    => $ticket->id,
            'ticket_number'         => $ticket->ticket_number,
            'status'                => $ticket->status,
            'side'                  => $ticket->side ?? 'YES',
            'my_side'               => $mySide,
            'amount'                => $ticket->amount,
            'odds'                  => $ticket->odds,
            'gross_payout'          => $ticket->gross_payout,
            'commission_percentage' => $ticket->commission_percentage,
            'commission_amount'     => $ticket->commission_amount,
            'net_payout'            => $ticket->net_payout,
            'my_role'               => $isCreator ? 'creator' : 'taker',
            'opponent_id'           => $isCreator ? $ticket->taker_id : $ticket->creator_id,
            'opponent_tag'          => $isCreator ? '#JOUEUR-' . $ticket->taker_id : '#JOUEUR-' . $ticket->creator_id,
            'winner_id'             => $ticket->winner_id,
            'is_winner'             => $ticket->winner_id === $userId,
            'matched_at'            => $ticket->matched_at?->toISOString(),
            'settled_at'            => $ticket->settled_at?->toISOString(),
            'created_at'            => $ticket->created_at->toISOString(),
            'market_title'          => $ticket->market?->title,
            'market_category'       => $ticket->market?->category,
            'match' => [
                'id'           => $ticket->market?->match?->id,
                'home'         => $ticket->market?->match?->clanHome?->name,
                'away'         => $ticket->market?->match?->clanAway?->name,
                'scheduled_at' => $ticket->market?->match?->scheduled_at?->toISOString(),
                'status'       => $ticket->market?->match?->status,
            ],
            'market_id'     => $ticket->market_id,
            'market_status' => $ticket->market?->status,
        ];
    }
}
