<?php

namespace Database\Seeders;

use App\Models\Clan;
use App\Models\Competition;
use App\Models\TournamentMatch;
use App\Models\BetMarket;
use App\Models\BetOption;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TournamentMatchSeeder extends Seeder
{
    /**
     * Run the database seeds for Tournament Matches & Clash Bet Markets.
     */
    public function run(): void
    {
        // 1. S'assurer qu'un utilisateur administrateur / capitaine existe
        $admin = User::firstOrCreate(
            ['tag_coc' => '#ADMINCCA'],
            [
                'name'      => 'CCA Admin',
                'password'  => bcrypt('password'),
                'role'      => 'admin',
                'hdv_level' => 16,
                'is_active' => true,
            ]
        );

        // 2. Récupérer ou créer la compétition active
        $competition = Competition::firstOrCreate(
            ['slug' => 'cca-national-league-s1'],
            [
                'name'                   => 'CCA National League - Saison 1',
                'season_number'          => 1,
                'format'                 => 'elimination_directe',
                'max_teams'              => 16,
                'registration_fee'       => 0,
                'status'                 => 'open',
                'registration_opens_at'  => now()->subDays(10),
                'registration_closes_at' => now()->addDays(5),
                'starts_at'              => now()->addDays(6),
                'ends_at'                => now()->addMonths(1),
                'prize_1st'              => 30000,
                'prize_2nd'              => 20000,
                'prize_3rd'              => 10000,
            ]
        );

        // 3. Création de Clans de démonstration
        $clansData = [
            ['tag' => '#2Q90P0YY0', 'name' => 'Les Victoires', 'level' => 15, 'points' => 1500, 'badge' => 'https://api-assets.clashofclans.com/badges/512/xYz123.png'],
            ['tag' => '#9V00G8RLP', 'name' => 'Lions eSport', 'level' => 12, 'points' => 1350, 'badge' => 'https://api-assets.clashofclans.com/badges/512/aBc456.png'],
            ['tag' => '#2YU9V9LLC', 'name' => 'Kamer Warriors', 'level' => 14, 'points' => 1420, 'badge' => 'https://api-assets.clashofclans.com/badges/512/DEF789.png'],
            ['tag' => '#8RRLL090C', 'name' => 'Bamenda Clashers', 'level' => 10, 'points' => 1100, 'badge' => 'https://api-assets.clashofclans.com/badges/512/GHI101.png'],
            ['tag' => '#PL098223C', 'name' => 'Douala Titans', 'level' => 16, 'points' => 1600, 'badge' => 'https://api-assets.clashofclans.com/badges/512/JKL202.png'],
            ['tag' => '#K9901238C', 'name' => 'Yaoundé Strikers', 'level' => 11, 'points' => 1250, 'badge' => 'https://api-assets.clashofclans.com/badges/512/MNO303.png'],
        ];

        $clans = [];
        foreach ($clansData as $item) {
            $clans[$item['name']] = Clan::firstOrCreate(
                ['tag_coc' => $item['tag']],
                [
                    'name'          => $item['name'],
                    'captain_id'    => $admin->id,
                    'badge_url'     => $item['badge'],
                    'clan_level'    => $item['level'],
                    'status'        => 'validated',
                    'total_points'  => $item['points'],
                    'seasons_played'=> 1,
                    'titles_won'    => 0,
                ]
            );
        }

        // 4. Matches de démonstration à insérer
        $matchesData = [
            [
                'competition_id' => $competition->id,
                'round'          => 1,
                'group'          => 'A',
                'phase'          => 'group_stage',
                'match_number'   => 1,
                'clan_home_id'   => $clans['Les Victoires']->id,
                'clan_away_id'   => $clans['Lions eSport']->id,
                'scheduled_at'   => now()->addHours(24),
                'status'         => 'scheduled',
            ],
            [
                'competition_id' => $competition->id,
                'round'          => 1,
                'group'          => 'A',
                'phase'          => 'group_stage',
                'match_number'   => 2,
                'clan_home_id'   => $clans['Kamer Warriors']->id,
                'clan_away_id'   => $clans['Bamenda Clashers']->id,
                'scheduled_at'   => now()->addHours(26),
                'status'         => 'scheduled',
            ],
            [
                'competition_id' => $competition->id,
                'round'          => 1,
                'group'          => 'B',
                'phase'          => 'group_stage',
                'match_number'   => 3,
                'clan_home_id'   => $clans['Douala Titans']->id,
                'clan_away_id'   => $clans['Yaoundé Strikers']->id,
                'scheduled_at'   => now()->addHours(2),
                'status'         => 'in_progress',
            ],
            [
                'competition_id' => $competition->id,
                'round'          => 1,
                'group'          => 'B',
                'phase'          => 'group_stage',
                'match_number'   => 4,
                'clan_home_id'   => $clans['Lions eSport']->id,
                'clan_away_id'   => $clans['Kamer Warriors']->id,
                'scheduled_at'   => now()->subDays(1),
                'status'         => 'completed',
                'winner_clan_id' => $clans['Lions eSport']->id,
                'total_stars_home' => 14,
                'total_stars_away' => 11,
                'total_destruction_home' => 98.50,
                'total_destruction_away' => 84.20,
            ],
        ];

        foreach ($matchesData as $mData) {
            $match = TournamentMatch::create($mData);

            // 5. Création automatique du Marché de Pari (Clash Bet Market) pour les matchs ouverts/en cours
            if (in_array($match->status, ['scheduled', 'in_progress'])) {
                $market = BetMarket::create([
                    'match_id'         => $match->id,
                    'status'           => 'open',
                    'liquidity_weight' => 100000, // FCFA liquidity buffer
                    'total_pool'       => 0,
                    'betting_closes_at'=> $match->scheduled_at,
                ]);

                // Option 1 : Clan Domicile
                BetOption::create([
                    'market_id'    => $market->id,
                    'label'        => $match->clanHome->name,
                    'clan_id'      => $match->clan_home_id,
                    'current_pool' => 0,
                ]);

                // Option 2 : Clan Extérieur
                BetOption::create([
                    'market_id'    => $market->id,
                    'label'        => $match->clanAway->name,
                    'clan_id'      => $match->clan_away_id,
                    'current_pool' => 0,
                ]);
            }
        }
    }
}
