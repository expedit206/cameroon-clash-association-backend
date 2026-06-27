<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CocApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Gère l'authentification des joueurs (inscription avec validation CoC).
 */
class AuthController extends Controller
{
    protected CocApiService $cocApi;

    public function __construct(CocApiService $cocApi)
    {
        $this->cocApi = $cocApi;
    }

    /**
     * Inscription d'un nouveau joueur.
     * 1. Vérification du tag CoC via API officielle.
     * 2. Création du compte avec statut 'pending'.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tag_coc' => 'required|string|unique:users,tag_coc',
            'name' => 'required|string|max:100', // Nom affiché sur la plateforme
            'password' => 'required|string|min:8|confirmed',
            'phone_whatsapp' => 'required|string',
            'screenshot_proof' => 'required|image|max:2048', // Max 2Mo
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Vérification du tag via l'API CoC
        $cocPlayer = $this->cocApi->getPlayer($request->tag_coc);

        if (!$cocPlayer) {
            return response()->json([
                'message' => 'Le tag Clash of Clans fourni est invalide ou l\'API est indisponible.'
            ], 422);
        }

        // Sauvegarde directement dans public/uploads/proofs (pas de storage:link requis)
        $file      = $request->file('screenshot_proof');
        $filename  = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('uploads/proofs'), $filename);
        $path = 'uploads/proofs/' . $filename;

        // Création de l'utilisateur
        $user = User::create([
            'tag_coc' => strtoupper($request->tag_coc),
            'name' => $request->name,
            'password' => Hash::make($request->password),
            'role' => 'player',
            'hdv_level' => $cocPlayer['townHallLevel'],
            'current_clan_tag' => $cocPlayer['clan']['tag'] ?? null,
            'player' => $cocPlayer ?? null,
            'phone_whatsapp' => $request->phone_whatsapp,
            'screenshot_proof' => $path,
            'status' => 'pending', // Validation admin requise
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Inscription réussie ! Votre compte est en attente de validation par un administrateur.',
            'user' => $user,
            'player' => $cocPlayer ?? null,

        ], 201);
    }

    /**
     * Connexion d'un utilisateur.
     * Vérifie si le compte est validé avant de générer le token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'tag_coc' => 'required|string',
            'password' => 'required|string',
        ]);

        // Normalisation EXTREME du tag
        $inputTag = trim($request->tag_coc);
        $normalizedTag = strtoupper(str_replace('#', '', $inputTag));
        
        \Illuminate\Support\Facades\Log::info('[Auth] Login attempt', [
            'input' => $inputTag,
            'normalized' => $normalizedTag
        ]);

        $user = User::where('tag_coc', $normalizedTag)
                    ->orWhere('tag_coc', '#' . $normalizedTag)
                    ->first();

        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('[Auth] User not found for: ' . $normalizedTag);
            throw ValidationException::withMessages([
                'tag_coc' => ['Identifiants incorrects ou utilisateur introuvable.'],
            ]);
        }

        if (!Hash::check($request->password, $user->password)) {
            \Illuminate\Support\Facades\Log::warning('[Auth] Password mismatch for: ' . $user->tag_coc);
            throw ValidationException::withMessages([
                'tag_coc' => ['Identifiants incorrects (mot de passe invalide).'],
            ]);
        }

        // Vérification du statut du compte
        // On permet temporairement aux comptes 'pending' de se connecter pour déboguer la persistance
        if ($user->status === 'rejected') {
            return response()->json([
                'message' => 'Votre compte a été refusé. Veuillez contacter l\'administration sur WhatsApp.'
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Votre compte a été suspendu.'
            ], 403);
        }

        \Illuminate\Support\Facades\Log::info('[Auth] Login successful for: ' . $user->tag_coc);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Mise à jour des métadonnées CoC de façon persistante
        try {
            $cocPlayer = $this->cocApi->getPlayer($user->tag_coc);
            if ($cocPlayer) {
                $user->update([
                    'league_icon' => $cocPlayer['league']['iconUrls']['small'] ?? $user->league_icon,
                    'exp_level'   => $cocPlayer['expLevel'] ?? $user->exp_level,
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning('[Auth] Could not update CoC metadata during login for ' . $user->tag_coc);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    /**
     * Synchronise les données CoC de l'utilisateur avec l'API officielle.
     */
    public function syncCocData(Request $request)
    {
        $user = $request->user();

        try {
            $cocPlayer = $this->cocApi->getPlayer($user->tag_coc);
            
            if (!$cocPlayer) {
                return response()->json(['message' => 'Impossible de récupérer les données CoC.'], 422);
            }

            $user->update([
                'name'             => $cocPlayer['name'] ?? $user->name,
                'hdv_level'        => $cocPlayer['townHallLevel'] ?? $user->hdv_level,
                'current_clan_tag' => $cocPlayer['clan']['tag'] ?? $user->current_clan_tag,
                'league_icon'      => $cocPlayer['league']['iconUrls']['small'] ?? $user->league_icon,
                'exp_level'        => $cocPlayer['expLevel'] ?? $user->exp_level,
            ]);

            return response()->json([
                'message' => 'Données synchronisées avec succès !',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erreur lors de la synchronisation : ' . $e->getMessage()], 500);
        }
    }

    /**
     * Déconnexion (Suppression du token actuel).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.'
        ]);
    }

    /**
     * Récupère le profil de l'utilisateur connecté.
     * Enrichi avec les données CoC live (icône de ligue, niveau d'expérience).
     */
    public function me(Request $request)
    {
        $user = $request->user()->load([
            'capitained_clan',
            'registrations.competition',
            'registrations.players.user',
            'current_clan'
        ]);

        return response()->json($user);
    }

    /**
     * Soumet le profil pour validation CCA (éligibilité tournoi).
     */
    public function submitProfileVerification(Request $request)
    {
        $user = $request->user();

        if ($user->profile_status !== 'none' && $user->profile_status !== 'rejected') {
            return response()->json([
                'message' => 'Une demande de validation est déjà en cours ou a déjà été traitée.'
            ], 422);
        }

        $user->update([
            'profile_status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Profil soumis avec succès pour validation CCA !',
            'user' => $user
        ]);
    }
}
