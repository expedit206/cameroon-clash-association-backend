<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ClanDiscoveryController;
use App\Http\Controllers\PlayerDiscoveryController;

// --- Authentification ---
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

// --- Public Tournament Data ---
Route::get('/tournament/leaderboard', [\App\Http\Controllers\TournamentController::class, 'getLeaderboard']);
Route::get('/tournament/bracket', [\App\Http\Controllers\TournamentController::class, 'getBracket']);

// --- Découverte des Clans & Joueurs ---
Route::get('/clans/cameroun', [ClanDiscoveryController::class, 'searchCamerounClans']);
Route::get('/players/cameroun', [PlayerDiscoveryController::class, 'getCamerounRankings']);
Route::middleware('auth:sanctum')->group(function () {
    // --- Profil & Auth ---
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/submit-profile', [AuthController::class, 'submitProfileVerification']);

    // --- Clans & Élections ---
    Route::get('/clans', [\App\Http\Controllers\ClanController::class, 'index']);
    Route::get('/clans/my-clan/members', [\App\Http\Controllers\ClanController::class, 'myClanMembers']);
    Route::get('/clans/{clan}', [\App\Http\Controllers\ClanController::class, 'show']);
    // --- Actions restreintes (Profil vérifié requis) ---
    Route::middleware('verified_profile')->group(function () {
        Route::post('/clans/submit', [\App\Http\Controllers\ClanController::class, 'submit']);
        Route::post('/competitions/{competition}/register-team', [\App\Http\Controllers\RegistrationController::class, 'registerTeam']);
        Route::post('/registrations/{registration}/pay', [\App\Http\Controllers\RegistrationController::class, 'initiatePayment']);
    });
    
    Route::post('/elections/initiate', [\App\Http\Controllers\ElectionController::class, 'initiate']);
    Route::post('/elections/{election}/vote', [\App\Http\Controllers\ElectionController::class, 'vote']);
    Route::post('/elections/{election}/declare-winner', [\App\Http\Controllers\ElectionController::class, 'declareWinner']);

    // --- Inscriptions & Equipes ---
    Route::get('/competitions/{competition}/registration/status', [\App\Http\Controllers\RegistrationController::class, 'status']);
    Route::get('/competitions/{competition}/registration/eligible-members', [\App\Http\Controllers\RegistrationController::class, 'getEligibleMembers']);

    // --- Administration ---
    Route::middleware('can:admin')->group(function () {
        // Dashboard Stats
        Route::get('/admin/stats', [\App\Http\Controllers\Admin\AdminUserController::class, 'stats']);

        // Moderation Joueurs
        Route::get('/admin/users/pending', [\App\Http\Controllers\Admin\AdminUserController::class, 'pendingUsers']);
        Route::put('/admin/users/{user}/validate', [\App\Http\Controllers\Admin\AdminUserController::class, 'validateUser']);
        Route::put('/admin/users/{user}/reject', [\App\Http\Controllers\Admin\AdminUserController::class, 'rejectUser']);
        
        // Moderation Clans
        Route::get('/admin/clans/pending', [\App\Http\Controllers\Admin\AdminClanController::class, 'pendingClans']);
        Route::put('/admin/clans/{clan}/validate', [\App\Http\Controllers\Admin\AdminClanController::class, 'validateClan']);
        Route::put('/admin/clans/{clan}/reject', [\App\Http\Controllers\Admin\AdminClanController::class, 'rejectClan']);

        // Inscriptions & Paiements
        Route::get('/admin/registrations', [\App\Http\Controllers\Admin\AdminRegistrationController::class, 'index']);
        Route::put('/admin/registrations/{registration}/confirm', [\App\Http\Controllers\Admin\AdminRegistrationController::class, 'confirm']);

        // Gestion Tournoi
        Route::post('/admin/competitions/{competition}/generate-bracket', [\App\Http\Controllers\Admin\AdminTournamentController::class, 'generateBracket']);
    });
});

