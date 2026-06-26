<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\BracketGeneratorService;
use Illuminate\Http\Request;

/**
 * Gestion du tournoi (génération, rounds) par les admins.
 */
class AdminTournamentController extends Controller
{
    public function generateBracket(Request $request, Competition $competition, BracketGeneratorService $service)
    {
        try {
            $service->generateInitialBracket($competition);
            return response()->json([
                'message' => "Le tableau du tournoi a été généré avec succès ! Le combat commence."
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
