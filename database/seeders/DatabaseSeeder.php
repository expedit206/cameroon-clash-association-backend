<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Competition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Création de l'Administrateur par défaut
        User::create([
            'tag_coc' => '#ADMINCCA',
            'name' => 'CCA Admin',
            'password' => Hash::make('password'), // À changer en production
            'role' => 'admin',
            'hdv_level' => 16,
            'is_active' => true,
        ]);

        // Création de la Saison 1 (Test)
        Competition::create([
            'name' => 'CCA National League - Saison 1',
            'slug' => 'cca-national-league-s1',
            'season_number' => 1,
            'format' => 'elimination_directe',
            'max_teams' => 16,
            'registration_fee' => 5000,
            'status' => 'open', // Inscriptions ouvertes
            'registration_opens_at' => now(),
            'registration_closes_at' => now()->addMonths(1),
            'starts_at' => now()->addMonths(2),
            'ends_at' => now()->addMonths(3),
            'prize_1st' => 30000,
            'prize_2nd' => 20000,
            'prize_3rd' => 0,
        ]);
    }
}
