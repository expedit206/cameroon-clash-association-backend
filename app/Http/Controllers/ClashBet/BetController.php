<?php

namespace App\Http\Controllers\ClashBet;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Services\ClashBetOddsService;
use App\Services\ClashBetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BetController extends Controller
{
    public function __construct(
        private readonly ClashBetOddsService $oddsService,
        private readonly ClashBetService     $betService
    ) {}

    /**
     * GET /clash-bet/markets
     * Liste des marchés ouverts avec cotes live.
     */
    public function markets(Request $request)
    {
        $publicEnabled = \App\Models\AppSetting::clashBetPublicEnabled();
        $isAdmin = Auth::check() && (Auth::user()->is_admin || Auth::user()->role === 'admin');

        if (!$publicEnabled && !$isAdmin) {
            return response()->json([
                'public_enabled' => false,
                'data'           => [],
                'message'        => 'Le Clash Bet P2P est actuellement fermé au public.',
            ]);
        }

        $markets = BetMarket::open()
            ->withDetails()
            ->latest()
            ->paginate(20);

        $markets->getCollection()->transform(function ($market) {
            return $this->formatMarket($market);
        });

        return response()->json($markets);
    }

    /**
     * GET /clash-bet/markets/{market}
     * Détail d'un marché avec cotes et statistiques.
     */
    public function show(BetMarket $market)
    {
        $market->load(['match.clanHome', 'match.clanAway', 'options']);
        return response()->json($this->formatMarket($market, true));
    }

    /**
     * GET /clash-bet/markets/preview-odds
     * Simule l'impact d'une mise sur les cotes (avant confirmation).
     */
    public function previewOdds(Request $request)
    {
        $request->validate([
            'option_id' => 'required|exists:bet_options,id',
            'amount'    => 'required|integer|min:500',
        ]);

        $option = BetOption::with('market')->findOrFail($request->option_id);
        $market = $option->market;

        $currentOdds  = $this->oddsService->computeOdds($market, $option);
        $simulatedOdds = $this->oddsService->simulateOdds($market, $option, $request->amount);
        $potentialPayout = (int) round($request->amount * $simulatedOdds);

        return response()->json([
            'current_odds'    => $currentOdds,
            'simulated_odds'  => $simulatedOdds,
            'amount'          => $request->amount,
            'potential_payout'=> $potentialPayout,
            'has_slippage'    => $this->oddsService->hasSlippage($currentOdds, $simulatedOdds, 0.05),
        ]);
    }

    /**
     * POST /clash-bet/bets
     * Placer un pari.
     */
    public function placeBet(Request $request)
    {
        $request->validate([
            'option_id'      => 'required|exists:bet_options,id',
            'amount'         => 'required|integer|min:100|max:5000000',
            'expected_odds'  => 'required|numeric|min:1.01',
        ]);

        $user   = Auth::user();
        $option = BetOption::with('market')->findOrFail($request->option_id);

        try {
            $result = $this->betService->placeBet(
                $user,
                $option,
                $request->amount,
                (float) $request->expected_odds
            );

            return response()->json([
                'success'        => true,
                'bet_id'         => $result['bet']->id,
                'executed_odds'  => $result['executed_odds'],
                'amount'         => $request->amount,
                'potential_payout'=> $result['bet']->potential_payout,
                'slippage'       => $result['slippage'],
                'message'        => $result['slippage']
                    ? "Pari accepté à {$result['executed_odds']} (cote mise à jour)."
                    : "Pari accepté à {$result['executed_odds']}.",
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /clash-bet/my-bets
     * Liste des paris de l'utilisateur connecté.
     */
    public function myBets(Request $request)
    {
        $status = $request->query('status'); // pending|won|lost|refunded

        $query = Bet::forUser(Auth::id())
            ->with(['market.match.clanHome', 'market.match.clanAway', 'option'])
            ->latest();

        if ($status) {
            $query->where('status', $status);
        }

        $bets = $query->paginate(20);

        $bets->getCollection()->transform(function ($bet) {
            return [
                'id'               => $bet->id,
                'amount'           => $bet->amount,
                'executed_odds'    => $bet->executed_odds,
                'potential_payout' => $bet->potential_payout,
                'actual_payout'    => $bet->actual_payout,
                'status'           => $bet->status,
                'settled_at'       => $bet->settled_at?->toISOString(),
                'created_at'       => $bet->created_at->toISOString(),
                'option'           => [
                    'id'    => $bet->option->id,
                    'label' => $bet->option->label,
                ],
                'match' => [
                    'id'       => $bet->market->match_id,
                    'home'     => $bet->market->match?->clanHome?->name,
                    'away'     => $bet->market->match?->clanAway?->name,
                    'status'   => $bet->market->match?->status,
                    'scheduled_at' => $bet->market->match?->scheduled_at?->toISOString(),
                ],
                'market_id'    => $bet->market_id,
                'market_status'=> $bet->market->status,
            ];
        });

        return response()->json($bets);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatMarket(BetMarket $market, bool $detailed = false): array
    {
        $allOdds   = $this->oddsService->computeAllOdds($market);
        $match     = $market->match;

        $data = [
            'id'               => $market->id,
            'status'           => $market->status,
            'total_pool'       => $market->total_pool,
            'betting_closes_at'=> $market->betting_closes_at?->toISOString(),
            'match' => [
                'id'           => $match?->id,
                'status'       => $match?->status,
                'scheduled_at' => $match?->scheduled_at?->toISOString(),
                'clan_home'    => [
                    'id'       => $match?->clanHome?->id,
                    'name'     => $match?->clanHome?->name,
                    'badge_url'=> $match?->clanHome?->badge_url ?? null,
                    'level'    => $match?->clanHome?->level ?? null,
                ],
                'clan_away'    => [
                    'id'       => $match?->clanAway?->id,
                    'name'     => $match?->clanAway?->name,
                    'badge_url'=> $match?->clanAway?->badge_url ?? null,
                    'level'    => $match?->clanAway?->level ?? null,
                ],
            ],
            'options' => $market->options->map(fn($opt) => [
                'id'           => $opt->id,
                'label'        => $opt->label,
                'clan_id'      => $opt->clan_id,
                'current_pool' => $opt->current_pool,
                'current_odds' => $allOdds[$opt->id] ?? 2.0,
            ]),
        ];

        return $data;
    }
}
