<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service pour interagir avec l'API officielle de Clash of Clans.
 * Documentation : https://developer.clashofclans.com/
 */
class CocApiService
{
    protected string $baseUrl;
    protected string $token;

    public function __construct()
    {
        $this->baseUrl = config('services.coc.base_url', "https://api.clashofclans.com/v1");
        $this->token = config('services.coc.token');
    }

    /**
     * Récupère les informations d'un joueur via son tag.
     * 
     * @param string $tag
     * @return array|null
     */
    public function getPlayer(string $tag): ?array
    {
        $encodedTag = $this->formatTag($tag);
        
        try {
            $response = Http::withToken($this->token)
                ->get("{$this->baseUrl}/players/{$encodedTag}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("CoC API Error (Player): " . $response->status() . " for tag {$tag}");
            return null;
        } catch (\Exception $e) {
            Log::error("CoC API Exception (Player): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère les informations d'un clan via son tag.
     * 
     * @param string $tag
     * @return array|null
     */
    public function getClan(string $tag): ?array
    {
        $encodedTag = $this->formatTag($tag);

        try {
            $response = Http::withToken($this->token)
                ->get("{$this->baseUrl}/clans/{$encodedTag}");

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("CoC API Error (Clan): " . $response->status() . " for tag {$tag}");
            return null;
        } catch (\Exception $e) {
            Log::error("CoC API Exception (Clan): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère les membres d'un clan.
     * 
     * @param string $tag
     * @return array|null
     */
    public function getClanMembers(string $tag): ?array
    {
        $encodedTag = $this->formatTag($tag);
        $url = "{$this->baseUrl}/clans/{$encodedTag}/members";

        Log::info("[CoC API] getClanMembers called", 
        [
            'original_tag' => $tag,
            'encoded_tag'  => $encodedTag,
            'url'          => $url,
            'token_set'    => !empty($this->token),
            'token_preview'=> substr($this->token ?? '', 0, 20) . '...',
        ]);

        try {
            $response = Http::withToken($this->token)->get($url);

            Log::info("[CoC API] getClanMembers response", [
                'status'       => $response->status(),
                'body_preview' => substr($response->body(), 0, 500)
            ]);

            if ($response->successful()) {
                $items = $response->json()['items'] ?? [];
                Log::info("[CoC API] Members found: " . count($items));
                return $items;
            }

            Log::error("[CoC API] getClanMembers failed: " . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error("[CoC API] getClanMembers exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Recherche des clans avec des filtres.
     * 
     * @param array $query
     * @return array|null
     */
    public function searchClans(array $query): ?array
    {
        try {
            $response = Http::withToken($this->token)
                ->get("{$this->baseUrl}/clans", $query);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error("CoC API Error (SearchClans): " . $response->status());
            return null;
        } catch (\Exception $e) {
            Log::error("CoC API Exception (SearchClans): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Formate le tag pour l'URL en remplaçant # par %23.
     * 
     * @param string $tag
     * @return string
     */
    protected function formatTag(string $tag): string
    {
        $tag = strtoupper(trim($tag));
        if (!str_starts_with($tag, '#')) {
            $tag = '#' . $tag;
        }
        return str_replace('#', '%23', $tag);
    }
}
