<?php
require 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Mock environment for CocApiService
class DebugCoc {
    public function getPlayerRankings($locationId) {
        $token = "TOKEN_PLACEHOLDER"; // I will extract the real token from .env if needed or just use current logic
        $baseUrl = "https://api.clashofclans.com/v1";
        
        // Try to get token from .env
        $env = parse_ini_file('.env');
        $token = $env['COC_API_TOKEN'] ?? $token;

        $response = Http::withToken($token)->get("$baseUrl/locations/$locationId/rankings/players");
        return $response->json();
    }
}

$api = new DebugCoc();
$results = $api->getPlayerRankings(32000046); // Cameroon

echo "PLAYER DATA SAMPLE:\n";
if (isset($results['items'][0])) {
    print_r($results['items'][0]);
} else {
    echo "No items found or API error.\n";
    print_r($results);
}
