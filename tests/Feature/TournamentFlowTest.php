<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\ClanRegistration;
use App\Models\Competition;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Services\BracketGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Teste le cycle complet de génération du tournoi.
     */
    public function test_complete_tournament_seeding_flow()
    {
        // 1. Créer une compétition
        $competition = Competition::create([
            'name' => 'CCA Season 1',
            'season' => 1,
            'status' => 'registration',
            'start_date' => now()->addDays(7),
        ]);

        // 2. Créer 16 clans avec des inscriptions confirmées
        for ($i = 1; $i <= 16; $i++) {
            $captain = User::factory()->create(['role' => 'captain']);
            $clan = Clan::create([
                'name' => "Clan Elite $i",
                'tag_coc' => "#CLAN$i",
                'captain_id' => $captain->id,
                'status' => 'validated',
            ]);

            ClanRegistration::create([
                'competition_id' => $competition->id,
                'clan_id' => $clan->id,
                'status' => 'confirmed',
                'seed_number' => $i,
            ]);
        }

        // 3. Lancer la génération du bracket
        $service = new BracketGeneratorService();
        $result = $service->generateInitialBracket($competition);

        $this->assertTrue($result);

        // 4. Vérifier les assertions
        // On doit avoir 8 matches pour le Round 1
        $this->assertEquals(8, TournamentMatch::where('competition_id', $competition->id)->where('round', 1)->count());

        // Vérifier l'appairage 1 vs 16 (Match 1)
        $match1 = TournamentMatch::where('match_number', 1)->first();
        $this->assertEquals("#CLAN1", $match1->clanHome->tag_coc);
        $this->assertEquals("#CLAN16", $match1->clanAway->tag_coc);

        // Vérifier l'appairage 2 vs 15 (Match 8 dans ma logique actuelle de BracketGeneratorService)
        $match8 = TournamentMatch::where('match_number', 8)->first();
        $this->assertEquals("#CLAN2", $match8->clanAway->tag_coc); // Dans mon service, 2 est le Away du match 8
        $this->assertEquals("#CLAN15", $match8->clanHome->tag_coc);
    }
}
