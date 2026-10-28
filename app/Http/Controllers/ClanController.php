<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\User;
use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Gère les clans et les candidatures des capitaines.
 */
class ClanController extends Controller
{
    protected CocApiService $cocApi;

    public function __construct(CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Soumission d'une candidature de clan par un capitaine.
     */
    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tag_coc' => 'required|string',
            'name' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // 1. Vérification du clan via l'API CoC
        $cocClan = $this->cocApi->getClan($request->tag_coc);

        if (!$cocClan) {
            return response()->json([
                'message' => "Le clan CoC {$request->tag_coc} est introuvable."
            ], 422);
        }

        // 2. Création ou mise à jour du clan
        $clan = Clan::updateOrCreate(
            ['tag_coc' => strtoupper($request->tag_coc)],
            [
                'name' => $request->name,
                'captain_id' => $user->id,
                'badge_url' => $cocClan['badgeUrls']['medium'] ?? null,
                'clan_level' => $cocClan['clanLevel'] ?? null,
                'status' => 'pending',
            ]
        );

        // Si l'utilisateur était un simple joueur, il devient capitaine
        if ($user->role === 'player') {
            $user->update(['role' => 'captain']);
        }

        return response()->json([
            'message' => "Candidature du clan {$clan->name} soumise avec succès !",
            'clan' => $clan
        ], 201);
    }

    /**
     * Récupère les membres du clan actuel de l'utilisateur avec leur statut sur la plateforme.
     */
    public function myClanMembers(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status !== 'validated') {
            return response()->json(['message' => "Votre profil doit être validé par la CCA pour accéder aux détails de votre clan."], 403);
        }

        $clanTag = $user->current_clan_tag;

        // \Illuminate\Support\Facades\Log::info('[My Clan] Request received', [
        //     'user_id'   => $user->id,
        //     'user_name' => $user->name,
        //     'clan_tag'  => $clanTag,
        // ]);

        if (!$clanTag) {
            return response()->json(['message' => "Tag de clan manquant. Veuillez synchroniser votre profil."], 400);
        }

        // 1. Récupérer les membres via l'API CoC
        $cocMembers = $this->cocApi->getClanMembers($clanTag);

        // \Illuminate\Support\Facades\Log::info('[My Clan] CoC members received', [
        //     'count' => $cocMembers ? count($cocMembers) : 'null (API failure)'
        // ]);

        if ($cocMembers === null) {
            return response()->json(['message' => "Impossible de récupérer les membres du clan depuis l'API CoC."], 500);
        }

        // 2. Récupérer les utilisateurs de la plateforme qui sont dans ce clan
        // On normalise les tags pour assurer une correspondance robuste
        $normalClanTag = strtoupper(trim($clanTag));
        $platformUsers = User::where(function($q) use ($normalClanTag) {
                $q->where('current_clan_tag', $normalClanTag)
                  ->orWhere('current_clan_tag', str_replace('#', '', $normalClanTag));
            })
            ->get()
            ->keyBy(function($u) {
                return strtoupper(trim($u->tag_coc));
            });

        // 3. Fusionner les données
        $members = array_map(function ($member) use ($platformUsers) {
            $tag = strtoupper(trim($member['tag']));
            $platformUser = $platformUsers->get($tag) ?? $platformUsers->get(str_replace('#', '', $tag));

            return [
                'id' => $platformUser?->id, // On aplatit l'ID pour faciliter l'accès au vote
                'tag_coc' => $tag,
                'name' => $member['name'],
                'townHallLevel' => $member['townHallLevel'],
                'role_coc' => $member['role'],
                'exp_level' => $member['expLevel'],
                'league_icon' => $member['leagueTier']['iconUrls']['small'] ?? null,
                'is_registered' => !!$platformUser,
                'platform_user' => $platformUser ? [
                    'id' => $platformUser->id,
                    'role' => $platformUser->role,
                    'status' => $platformUser->status,
                    'is_validated' => $platformUser->is_validated ?? false,
                ] : null,
            ];
        }, $cocMembers);

        // 4. Vérifier si une élection est en cours
        $activeElection = \App\Models\CaptainElection::where('clan_tag', $clanTag)
            ->where('status', 'open')
            ->with(['votes']) // On pourrait charger les votes pour voir si l'user a déjà voté
            ->first();

        return response()->json([
            'clan_tag' => $clanTag,
            'members' => $members,
            'active_election' => $activeElection
        ]);
    }

    /**
     * Liste des clans validés pour navigation publique.
     */
    public function index()
    {
        $clans = Clan::where('status', 'validated')->get();
        return response()->json($clans);
    }

    /**
     * Détails d'un clan avec son profil, ses membres et son parcours complet en tournoi.
     * Le tag arrive avec ou sans '#'.
     */
    public function show(string $tag)
    {
        $decodedTag = urldecode($tag);
        $normalizedTag = strtoupper(trim($decodedTag));
        if (!str_starts_with($normalizedTag, '#')) {
            $normalizedTag = '#' . $normalizedTag;
        }

        // 1. Récupérer le clan en BDD
        $clan = Clan::where('tag_coc', $normalizedTag)
            ->orWhere('tag_coc', str_replace('#', '', $normalizedTag))
            ->first();

        // 2. Tenter de récupérer les données CoC pour les membres / visuel si nécessaire
        $cocClan = $this->cocApi->getClan($normalizedTag);

        if (!$clan && !$cocClan) {
            return response()->json([
                'message' => "Clan '{$normalizedTag}' introuvable."
            ], 404);
        }

        // Si le clan n'existait pas en BDD mais existe dans CoC API, le créer à la volée
        if (!$clan && $cocClan) {
            $clan = Clan::create([
                'tag_coc'    => $cocClan['tag'],
                'name'       => $cocClan['name'],
                'badge_url'  => $cocClan['badgeUrls']['medium'] ?? null,
                'clan_level' => $cocClan['clanLevel'] ?? 1,
                'status'     => 'validated',
            ]);
        }

        // 3. Récupérer l'inscription au tournoi
        $registration = \App\Models\ClanRegistration::where('clan_id', $clan->id)
            ->where('competition_id', 1)
            ->first();

        // 4. Récupérer tous les matchs du clan dans la compétition 1 (Poules + Éliminatoires)
        $matches = \App\Models\TournamentMatch::with(['clanHome', 'clanAway'])
            ->where('competition_id', 1)
            ->where(function ($q) use ($clan) {
                $q->where('clan_home_id', $clan->id)
                  ->orWhere('clan_away_id', $clan->id);
            })
            ->orderBy('phase', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $played = 0;
        $won = 0;
        $drawn = 0;
        $lost = 0;
        $totalStars = 0;
        $totalDestruction = 0.0;

        $formattedMatches = [];

        foreach ($matches as $match) {
            $isHome = $match->clan_home_id === $clan->id;
            $opponent = $isHome ? $match->clanAway : $match->clanHome;

            $myStars   = (int) ($isHome ? $match->total_stars_home : $match->total_stars_away);
            $oppStars  = (int) ($isHome ? $match->total_stars_away : $match->total_stars_home);
            $myDest    = (float) ($isHome ? $match->total_destruction_home : $match->total_destruction_away);
            $oppDest   = (float) ($isHome ? $match->total_destruction_away : $match->total_destruction_home);

            $result = 'pending';

            if ($match->status === 'completed') {
                $played++;
                $totalStars += $myStars;
                $totalDestruction += $myDest;

                if ($myStars > $oppStars) {
                    $result = 'win';
                    $won++;
                } elseif ($oppStars > $myStars) {
                    $result = 'loss';
                    $lost++;
                } else {
                    if ($myDest > $oppDest) {
                        $result = 'win';
                        $won++;
                    } elseif ($oppDest > $myDest) {
                        $result = 'loss';
                        $lost++;
                    } else {
                        $result = 'draw';
                        $drawn++;
                    }
                }
            }

            $formattedMatches[] = [
                'id'                   => $match->id,
                'phase'                => $match->phase,
                'group'                => $match->group,
                'status'               => $match->status,
                'scheduled_at'         => $match->scheduled_at,
                'is_home'              => $isHome,
                'opponent'             => $opponent ? [
                    'id'        => $opponent->id,
                    'name'      => $opponent->name,
                    'tag_coc'   => $opponent->tag_coc,
                    'badge_url' => $opponent->badge_url,
                ] : null,
                'my_stars'             => $myStars,
                'opp_stars'            => $oppStars,
                'my_destruction'       => $myDest,
                'opp_destruction'      => $oppDest,
                'result'               => $result,
            ];
        }

        $points = ($won * 3) + ($drawn * 1);
        $avgDestruction = $played > 0 ? round($totalDestruction / $played, 2) : 0.0;

        return response()->json([
            'name'             => $clan->name,
            'tag_coc'          => $clan->tag_coc,
            'clan_level'       => $clan->clan_level ?? ($cocClan['clanLevel'] ?? 1),
            'badge_url'        => $clan->badge_url ?? ($cocClan['badgeUrls']['medium'] ?? null),
            'description'      => $cocClan['description'] ?? null,
            'type'             => $cocClan['type'] ?? null,
            'location'         => $cocClan['location'] ?? null,
            'members'          => $cocClan['members'] ?? [],
            'members_count'    => $cocClan['members'] ? count($cocClan['members']) : 0,

            // Données du tournoi
            'tournament_registration' => $registration ? [
                'status' => $registration->status,
                'group'  => $registration->group,
                'seed'   => $registration->seed_number,
            ] : null,

            'tournament_stats' => [
                'played'          => $played,
                'won'             => $won,
                'drawn'           => $drawn,
                'lost'            => $lost,
                'points'          => $points,
                'total_stars'     => $totalStars,
                'avg_destruction' => $avgDestruction,
            ],

            'tournament_matches' => $formattedMatches,
        ]);
    }
}
