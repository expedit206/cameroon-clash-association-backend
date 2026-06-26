<?php

namespace App\Http\Controllers;

use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClanDiscoveryController extends Controller
{
    protected CocApiService $cocApi;

    public function __construct(CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Recherche des clans camerounais via l'API CoC.
     * Location ID Cameroun : 32000045
     */
    public function searchCamerounClans(Request $request)
    {
        $query = [
            'locationId' => 32000045, // Cameroun
            'limit'      => $request->query('limit', 20),
            'after'      => $request->query('after'),
        ];

        // Filtres optionnels
        if ($request->has('name')) {
            $query['name'] = $request->query('name');
        }
        if ($request->has('minMembers')) {
            $query['minMembers'] = $request->query('minMembers');
        }
        if ($request->has('minClanLevel')) {
            $query['minClanLevel'] = $request->query('minClanLevel');
        }

        $results = $this->cocApi->searchClans($query);

        if (!$results) {
            return response()->json([
                'message' => 'Erreur lors de la récupération des clans camerounais.',
                'items'   => []
            ], 500);
        }

        return response()->json($results);
    }
}
