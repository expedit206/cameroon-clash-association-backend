<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Models\Clan;
use App\Models\ClanRegistration;
use App\Models\Competition;
use App\Models\RegistrationPlayer;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\ClashBetOddsService;
use App\Services\ClashBetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClashBetTest extends TestCase
{
    use RefreshDatabase;

    private User $user1;
    private User $user2;
    private TournamentMatch $match;
    private BetMarket $market;
    private BetOption $optionHome;
    private BetOption $optionAway;
    private ClashBetService $betService;
    private ClashBetOddsService $oddsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oddsService = new ClashBetOddsService();
        $this->betService  = new ClashBetService($this->oddsService);

        // 1. Créer deux utilisateurs
        $this->user1 = User::factory()->create(['name' => 'Parietole 1', 'role' => 'player']);
        $this->user2 = User::factory()->create(['name' => 'Parietole 2', 'role' => 'player']);

        // Créditer leurs wallets
        $this->user1->getOrCreateWallet()->credit(50000, 'deposit', 'Dépôt initial de test');
        $this->user2->getOrCreateWallet()->credit(100000, 'deposit', 'Dépôt initial de test');

        // 2. Créer une compétition et un match entre 2 clans
        $competition = Competition::create([
            'name'       => 'CCA Season 1',
            'season'     => 1,
            'status'     => 'in_progress',
            'start_date' => now(),
        ]);

        $clanA = Clan::create(['name' => 'Clan Alpha', 'tag_coc' => '#ALPHA1', 'status' => 'validated']);
        $clanB = Clan::create(['name' => 'Clan Omega', 'tag_coc' => '#OMEGA1', 'status' => 'validated']);

        $this->match = TournamentMatch::create([
            'competition_id' => $competition->id,
            'clan_home_id'   => $clanA->id,
            'clan_away_id'   => $clanB->id,
            'round'          => 1,
            'match_number'   => 1,
            'status'         => 'scheduled',
        ]);

        // 3. Créer un marché de pari avec 2 options
        $this->market = BetMarket::create([
            'match_id'         => $this->match->id,
            'status'           => 'open',
            'liquidity_weight' => 100000,
            'total_pool'       => 0,
        ]);

        $this->optionHome = BetOption::create([
            'market_id' => $this->market->id,
            'label'     => 'Clan Alpha',
            'clan_id'   => $clanA->id,
        ]);

        $this->optionAway = BetOption::create([
            'market_id' => $this->market->id,
            'label'     => 'Clan Omega',
            'clan_id'   => $clanB->id,
        ]);
    }

    /**
     * Test : Calcul des cotes initiales avec la Bonding Curve (2.00 / 2.00 à l'ouverture).
     */
    public function test_initial_odds_are_balanced()
    {
        $oddsHome = $this->oddsService->computeOdds($this->market, $this->optionHome);
        $oddsAway = $this->oddsService->computeOdds($this->market, $this->optionAway);

        $this->assertEquals(2.00, $oddsHome);
        $this->assertEquals(2.00, $oddsAway);
    }

    /**
     * Test : Placement d'un pari atomique avec verrouillage de solde et de la cote exécutée.
     */
    public function test_place_bet_locks_balance_and_executed_odds()
    {
        $amount = 10000;
        $result = $this->betService->placeBet($this->user1, $this->optionHome, $amount, 2.00);

        $bet = $result['bet'];

        $this->assertEquals(10000, $bet->amount);
        $this->assertEquals(2.00, $bet->executed_odds);
        $this->assertEquals('pending', $bet->status);

        // Vérifier le solde du wallet
        $wallet = $this->user1->wallet->fresh();
        $this->assertEquals(40000, $wallet->balance); // 50 000 - 10 000
        $this->assertEquals(10000, $wallet->locked_amount);

        // Vérifier les pools du marché
        $this->market->refresh();
        $this->optionHome->refresh();
        $this->assertEquals(10000, $this->market->total_pool);
        $this->assertEquals(10000, $this->optionHome->current_pool);
    }

    /**
     * Test : Évolution de la cote après des paris successifs (Dynamic Bonding Curve).
     */
    public function test_odds_evolve_dynamically_after_bets()
    {
        // Pari 10 000 FCFA sur Home par User 1
        $this->betService->placeBet($this->user1, $this->optionHome, 10000, 2.00);

        $this->market->refresh();
        $newOddsHome = $this->oddsService->computeOdds($this->market, $this->optionHome);
        $newOddsAway = $this->oddsService->computeOdds($this->market, $this->optionAway);

        // La cote sur Home doit baisser (< 2.00) et la cote sur Away doit monter (> 2.00)
        $this->assertLessThan(2.00, $newOddsHome);
        $this->assertGreaterThan(2.00, $newOddsAway);
    }

    /**
     * Test : Rejet si solde insuffisant.
     */
    public function test_cannot_bet_with_insufficient_funds()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Solde insuffisant');

        $this->betService->placeBet($this->user1, $this->optionHome, 999999, 2.00);
    }

    /**
     * Test : Sécurité Anti-Match-Fixing (Roster Player).
     */
    public function test_roster_player_cannot_bet_on_own_match()
    {
        // Inscrire user1 dans le roster de Clan Alpha pour ce match
        $registration = ClanRegistration::create([
            'competition_id' => $this->match->competition_id,
            'clan_id'        => $this->match->clan_home_id,
            'status'         => 'confirmed',
        ]);

        RegistrationPlayer::create([
            'registration_id' => $registration->id,
            'player_id'       => $this->user1->id,
            'role'            => 'main',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peuvent pas y parier');

        $this->betService->placeBet($this->user1, $this->optionHome, 1000, 2.00);
    }

    /**
     * Test : Règlement du marché (Distribution de 100% du pool aux gagnants à leurs cotes exécutées).
     */
    public function test_settle_market_distributes_gains_to_winners()
    {
        // User 1 mise 10 000 FCFA sur Home @ 2.00 (Gain potentiel = 20 000)
        $bet1 = $this->betService->placeBet($this->user1, $this->optionHome, 10000, 2.00)['bet'];

        // User 2 mise 20 000 FCFA sur Away @ 2.00
        $bet2 = $this->betService->placeBet($this->user2, $this->optionAway, 20000, 2.00)['bet'];

        // Régler le marché avec Option Home gagnante (Clan Alpha)
        $this->market->update(['status' => 'closed']);
        $stats = $this->betService->settleMarket($this->market, $this->optionHome->id);

        $this->assertEquals(1, $stats['winners']);
        $this->assertEquals(1, $stats['losers']);
        $this->assertEquals(20000, $stats['total_paid']);

        // Vérifier le solde de User 1 (gagnant) : initial 50k - 10k mise + 20k gain = 60 000 FCFA
        $wallet1 = $this->user1->wallet->fresh();
        $this->assertEquals(60000, $wallet1->balance);
        $this->assertEquals(0, $wallet1->locked_amount);

        // Vérifier le solde de User 2 (perdu) : initial 100k - 20k mise = 80 000 FCFA
        $wallet2 = $this->user2->wallet->fresh();
        $this->assertEquals(80000, $wallet2->balance);
        $this->assertEquals(0, $wallet2->locked_amount);
    }

    /**
     * Test : Annulation de marché et remboursement à 100%.
     */
    public function test_cancel_market_refunds_all_bets()
    {
        $this->betService->placeBet($this->user1, $this->optionHome, 10000, 2.00);

        $this->betService->cancelMarket($this->market, 'Match annulé météo');

        $wallet1 = $this->user1->wallet->fresh();
        $this->assertEquals(50000, $wallet1->balance); // Remboursé intégralement
        $this->assertEquals(0, $wallet1->locked_amount);

        $this->market->refresh();
        $this->assertEquals('cancelled', $this->market->status);
    }

    /**
     * Test : Retrait avec calcul et déduction automatique des 7% de frais.
     */
    public function test_withdrawal_deducts_7_percent_fee()
    {
        $wallet = $this->user1->getOrCreateWallet();

        // Demande de retrait de 10 000 FCFA
        $withdrawal = $wallet->debitForWithdrawal(10000, 'orange_money', '690000000');

        $this->assertEquals(10000, $withdrawal->amount);
        $this->assertEquals(700, $withdrawal->fee); // 7% de 10 000 = 700 FCFA
        $this->assertEquals(9300, $withdrawal->net_amount); // 10 000 - 700 = 9 300 FCFA

        // Solde débité de 10 000 FCFA
        $this->assertEquals(40000, $wallet->fresh()->balance);
    }
}
