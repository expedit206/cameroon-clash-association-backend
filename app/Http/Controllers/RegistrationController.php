<?php

namespace App\Http\Controllers;

use App\Models\ClanRegistration;
use App\Models\Competition;
use App\Models\RegistrationPlayer;
use App\Models\User;
use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Gère les inscriptions des clans aux compétitions (Phase 2 et 3).
 */
class RegistrationController extends Controller
{
    protected CocApiService $cocApi;

    public function __construct(CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Récupère les membres du clan CoC éligibles pour l'inscription.
     */
    public function getEligibleMembers(Request $request, Competition $competition)
    {
        $user = $request->user();
        $clan = $user->capitainedClan;

        if (!$clan) {
            return response()->json(['message' => "Vous n'avez pas de clan enregistré."], 403);
        }

        $members = $this->cocApi->getClanMembers($clan->tag_coc);

        if (!$members) {
            return response()->json(['message' => "Impossible de récupérer les membres du clan."], 422);
        }

        // Filtrer les membres déjà inscrits dans cette compétition via d'autres clans (optionnel mais recommandé)
        // Pour l'instant, on retourne la liste brute. En V2, on vérifiera si le tag est déjà dans registration_players.

        return response()->json($members);
    }

    /**
     * Soumettre la composition de l'équipe (Phase 2).
     */
    public function registerTeam(Request $request, Competition $competition)
    {
        $user = $request->user();
        $clan = $user->capitainedClan;

        if (!$clan || $clan->status !== 'validated') {
            return response()->json(['message' => "Votre clan doit être validé par l'admin avant l'inscription."], 403);
        }

        $validator = Validator::make($request->all(), [
            'players' => 'required|array|min:5|max:10', // 5 titulaires + jusqu'à 5 remplaçants
            'players.*.tag_coc' => 'required|string',
            'players.*.name' => 'required|string',
            'players.*.hdv_level' => 'required|integer|between:14,18',
            'players.*.is_substitute' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $competition, $clan) {
            // 1. Créer ou récupérer l'inscription
            $registration = ClanRegistration::firstOrCreate([
                'clan_id' => $clan->id,
                'competition_id' => $competition->id,
            ], [
                'status' => 'pending_payment',
            ]);

            // 2. Nettoyer la composition précédente si elle existe
            $registration->players()->delete();

            // 3. Ajouter les joueurs
            foreach ($request->players as $playerData) {
                // On cherche l'utilisateur par son tag, ou on en crée un "fantôme" si pas encore inscrit(si le joueur n'est pas encore inscrit dans notre plateforme on refusera son appartenance a une equipe)
                $player = User::firstOrCreate(
                    ['tag_coc' => strtoupper($playerData['tag_coc'])],
                    [
                        'name' => $playerData['name'],
                        'password' => bcrypt('password_default'), // Password temporaire
                        'role' => 'player',
                        'hdv_level' => $playerData['hdv_level'],
                        'status' => 'validated', // Validé tacitement car vérifié par le capitaine
                    ]
                );

                RegistrationPlayer::create([
                    'clan_registration_id' => $registration->id,
                    'player_id' => $player->id,
                    'hdv_position' => $playerData['hdv_level'],
                    'is_substitute' => $playerData['is_substitute'],
                    'verified_at' => now(),
                ]);
            }

            return response()->json([
                'message' => "Composition d'équipe enregistrée. Passez maintenant au paiement.",
                'registration' => $registration->load('players.user')
            ]);
        });
    }

    /**
     * Récupérer le statut de l'inscription du clan connecté.
     */
    public function status(Request $request, Competition $competition)
    {
        $clan = $request->user()->capitainedClan;
        
        if (!$clan) return response()->json(['message' => 'Aucun clan'], 404);

        $registration = ClanRegistration::where('clan_id', $clan->id)
            ->where('competition_id', $competition->id)
            ->with(['players.user', 'payment'])
            ->first();

        return response()->json($registration);
    }

    /**
     * Initier le paiement (Phase 3).
     */
    public function initiatePayment(Request $request, ClanRegistration $registration)
    {
        $request->validate([
            'payment_method' => 'required|string',
            'phone_number' => 'required|string',
            'transaction_reference' => 'required|string',
        ]);

        $registration->update([
            'status' => 'pending_confirmation',
            'payment_reference' => $request->transaction_reference,
            'paid_at' => now(),
        ]);

        // Créer l'entrée dans la table payments
        \App\Models\Payment::updateOrCreate(
            ['clan_registration_id' => $registration->id],
            [
                'amount' => $registration->competition->registration_fee,
                'currency' => 'XAF',
                'reference' => $request->transaction_reference,
                'status' => 'pending',
                'payment_method' => $request->payment_method,
                'phone_number' => $request->phone_number,
            ]
        );

        return response()->json([
            'message' => "Paiement soumis. L'administrateur validera votre inscription sous peu."
        ]);
    }
}
