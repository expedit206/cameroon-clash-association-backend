<?php

namespace Tests\Feature;

use App\Models\BetMarket;
use App\Models\Clan;
use App\Models\Competition;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThirdPlaceAndTiebreakTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_override_match_winner_tiebreaker()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $comp = Competition::create(['name' => 'Test Comp', 'season' => 1, 'status' => 'active']);
        $clan1 = Clan::create(['name' => 'Clan Alpha', 'tag_coc' => '#ALPHA', 'status' => 'validated']);
        $clan2 = Clan::create(['name' => 'Clan Beta', 'tag_coc' => '#BETA', 'status' => 'validated']);

        $match = TournamentMatch::create([
            'competition_id' => $comp->id,
            'phase' => 'semi_final',
            'round' => 2,
            'match_number' => 1,
            'clan_home_id' => $clan1->id,
            'clan_away_id' => $clan2->id,
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($admin)
            ->putJson("/api/admin/matches/{$match->id}", [
                'total_stars_home' => 10,
                'total_stars_away' => 10,
                'total_destruction_home' => 85.0,
                'total_destruction_away' => 85.0,
                'status' => 'completed',
                'winner_clan_id' => $clan2->id, // Force Clan Beta as winner
            ]);

        $response->assertStatus(200);
        $match->refresh();
        $this->assertEquals($clan2->id, $match->winner_clan_id);
    }

    public function test_generate_third_place_match_and_auto_final()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $comp = Competition::create(['name' => 'Test Comp', 'season' => 1, 'status' => 'active']);

        $c1 = Clan::create(['name' => 'Clan 1', 'tag_coc' => '#C1', 'status' => 'validated']);
        $c2 = Clan::create(['name' => 'Clan 2', 'tag_coc' => '#C2', 'status' => 'validated']);
        $c3 = Clan::create(['name' => 'Clan 3', 'tag_coc' => '#C3', 'status' => 'validated']);
        $c4 = Clan::create(['name' => 'Clan 4', 'tag_coc' => '#C4', 'status' => 'validated']);

        // SF1: C1 vs C2 (C1 wins, C2 loses)
        $sf1 = TournamentMatch::create([
            'competition_id' => $comp->id,
            'phase' => 'semi_final',
            'round' => 2,
            'match_number' => 1,
            'clan_home_id' => $c1->id,
            'clan_away_id' => $c2->id,
            'status' => 'scheduled',
        ]);

        // SF2: C3 vs C4 (C3 wins, C4 loses)
        $sf2 = TournamentMatch::create([
            'competition_id' => $comp->id,
            'phase' => 'semi_final',
            'round' => 2,
            'match_number' => 2,
            'clan_home_id' => $c3->id,
            'clan_away_id' => $c4->id,
            'status' => 'scheduled',
        ]);

        // Complete SF1
        $this->actingAs($admin)->putJson("/api/admin/matches/{$sf1->id}", [
            'total_stars_home' => 15,
            'total_stars_away' => 12,
            'status' => 'completed',
        ]);

        // Complete SF2
        $this->actingAs($admin)->putJson("/api/admin/matches/{$sf2->id}", [
            'total_stars_home' => 14,
            'total_stars_away' => 10,
            'status' => 'completed',
        ]);

        // Verify Auto Final is created (C1 vs C3)
        $final = TournamentMatch::where('competition_id', $comp->id)->where('phase', 'final')->first();
        $this->assertNotNull($final);
        $this->assertEquals($c1->id, $final->clan_home_id);
        $this->assertEquals($c3->id, $final->clan_away_id);

        // Generate 3rd Place Match (C2 vs C4)
        $res3rd = $this->actingAs($admin)->postJson("/api/admin/competitions/{$comp->id}/generate-third-place");
        $res3rd->assertStatus(200);

        $match3rd = TournamentMatch::where('competition_id', $comp->id)->where('phase', 'third_place')->first();
        $this->assertNotNull($match3rd);
        $this->assertEquals($c2->id, $match3rd->clan_home_id);
        $this->assertEquals($c4->id, $match3rd->clan_away_id);
    }

    public function test_delete_match_markets()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $comp = Competition::create(['name' => 'Test Comp', 'season' => 1, 'status' => 'active']);
        $c1 = Clan::create(['name' => 'Clan 1', 'tag_coc' => '#C1', 'status' => 'validated']);
        $c2 = Clan::create(['name' => 'Clan 2', 'tag_coc' => '#C2', 'status' => 'validated']);

        $match = TournamentMatch::create([
            'competition_id' => $comp->id,
            'phase' => 'group_stage',
            'clan_home_id' => $c1->id,
            'clan_away_id' => $c2->id,
            'status' => 'scheduled',
        ]);

        BetMarket::create([
            'match_id' => $match->id,
            'title' => 'Vainqueur du match',
            'category' => 'match_winner',
            'status' => 'open',
        ]);

        $this->assertEquals(1, BetMarket::where('match_id', $match->id)->count());

        $response = $this->actingAs($admin)->deleteJson("/api/admin/clash-bet/matches/{$match->id}/markets");
        $response->assertStatus(200);

        $this->assertEquals(0, BetMarket::where('match_id', $match->id)->count());
    }
}
