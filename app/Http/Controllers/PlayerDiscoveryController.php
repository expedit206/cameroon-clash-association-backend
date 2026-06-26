<?php

namespace App\Http\Controllers;

use App\Services\CocApiService;
use Illuminate\Http\Request;

class PlayerDiscoveryController extends Controller
{
    protected CocApiService $cocApi;

    public function __construct(CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Récupère le classement des joueurs camerounais via l'API CoC.
     * Location ID Cameroun : 32000046
     */
    public function getCamerounRankings(Request $request)
    {
        $locationId = 32000046; // Cameroun
        
        $results = $this->cocApi->getPlayerRankings($locationId);

        if (!$results) {
            return response()->json([
                'message' => 'Erreur lors de la récupération des classements camerounais.',
                'items'   => []
            ], 500);
        }

        return response()->json($results);
    }
}
