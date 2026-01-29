<?php
// routes/api.php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TournamentController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\AdvertisementController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\LiguillaController;

// CORS se maneja en config/cors.php y el middleware HandleCors

/*
|--------------------------------------------------------------------------
| Rutas de Autenticación
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
    });
});

/*
|--------------------------------------------------------------------------
| Rutas Públicas (sin autenticación)
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    // Jugadores
    Route::get('players', [PlayerController::class, 'index']);
    Route::get('players/statistics/top', [PlayerController::class, 'statistics']);
    Route::get('players/{player}', [PlayerController::class, 'show']);

    // Equipos
    Route::get('teams', [TeamController::class, 'index']);
    Route::get('teams/standings/table', [TeamController::class, 'standings']);
    Route::get('teams/{team}', [TeamController::class, 'show']);

    // Partidos
    Route::get('matches', [MatchController::class, 'index']);
    Route::get('matches/upcoming/list', [MatchController::class, 'upcoming']);
    Route::get('matches/live/current', [MatchController::class, 'live']);
    Route::get('matches/{match}', [MatchController::class, 'show']);

    // Torneos
    Route::get('tournaments', [TournamentController::class, 'index']);
    Route::get('tournaments/{tournament}', [TournamentController::class, 'show']);
    Route::get('tournaments/{tournament}/teams', [TournamentController::class, 'getTeams']);

    // Publicidad
    Route::get('advertisements', [AdvertisementController::class, 'active']);

    // Exportación pública (solo lectura)
    Route::get('tournaments/{tournament}/export/standings', [ExportController::class, 'standings']);
    Route::get('tournaments/{tournament}/export/fixture', [ExportController::class, 'fixture']);

    // Liguilla (lectura pública)
    Route::get('tournaments/{tournament}/standings', [LiguillaController::class, 'standings']);
    Route::get('tournaments/{tournament}/phases', [LiguillaController::class, 'getPhases']);
    Route::get('phases/{phase}/bracket', [LiguillaController::class, 'getBracket']);
});

/*
|--------------------------------------------------------------------------
| Rutas Protegidas (requieren autenticación)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Jugadores
    Route::post('players', [PlayerController::class, 'store']);
    Route::put('players/{player}', [PlayerController::class, 'update']);
    Route::delete('players/{player}', [PlayerController::class, 'destroy']);

    // Equipos
    Route::post('teams', [TeamController::class, 'store']);
    Route::put('teams/{team}', [TeamController::class, 'update']);
    Route::delete('teams/{team}', [TeamController::class, 'destroy']);
    Route::post('teams/{team}/players', [TeamController::class, 'addPlayer']);
    Route::delete('teams/{team}/players/{player}', [TeamController::class, 'removePlayer']);

    // Torneos
    Route::post('tournaments', [TournamentController::class, 'store']);
    Route::put('tournaments/{tournament}', [TournamentController::class, 'update']);
    Route::delete('tournaments/{tournament}', [TournamentController::class, 'destroy']);

    // Gestión de equipos en torneo
    Route::post('tournaments/{tournament}/teams', [TournamentController::class, 'addTeams']);
    Route::post('tournaments/{tournament}/add-team', [TournamentController::class, 'addTeamToActiveTournament']);
    Route::delete('tournaments/{tournament}/teams/{team}', [TournamentController::class, 'removeTeam']);

    // Generación de fixture
    Route::post('tournaments/{tournament}/generate-fixture', [TournamentController::class, 'generateFixture']);
    Route::post('tournaments/{tournament}/regenerate-fixture', [TournamentController::class, 'regenerateFixture']);
    Route::post('tournaments/{tournament}/clone', [TournamentController::class, 'cloneTournament']);

    // Jornadas
    Route::post('tournaments/{tournament}/matchdays', [TournamentController::class, 'createMatchday']);
    Route::put('matchdays/{matchday}', [TournamentController::class, 'updateMatchday']);
    Route::delete('matchdays/{matchday}', [TournamentController::class, 'deleteMatchday']);

    // Partidos
    Route::post('matchdays/{matchday}/matches', [MatchController::class, 'store']);
    Route::put('matches/{match}', [MatchController::class, 'update']);
    Route::delete('matches/{match}', [MatchController::class, 'destroy']);
    Route::post('matches/{match}/events', [MatchController::class, 'addEvent']);
    Route::delete('matches/{match}/events/{event}', [MatchController::class, 'removeEvent']);
    Route::put('matches/{match}/status', [MatchController::class, 'updateStatus']);
    Route::put('matches/{match}/start', [MatchController::class, 'startMatch']);
    Route::put('matches/{match}/finish', [MatchController::class, 'finishMatch']);

    // Liguilla
    Route::post('tournaments/{tournament}/generate-liguilla', [LiguillaController::class, 'generateLiguilla']);
    Route::delete('tournaments/{tournament}/liguilla', [LiguillaController::class, 'deleteLiguilla']);
    Route::post('brackets/{bracket}/advance-winner', [LiguillaController::class, 'advanceWinner']);
    Route::post('brackets/{bracket}/set-winner', [LiguillaController::class, 'setWinner']);
    Route::post('phases/{phase}/create-next-round', [LiguillaController::class, 'createNextRoundMatches']);

    // Publicidad
    Route::post('advertisements', [AdvertisementController::class, 'store']);
    Route::put('advertisements/{advertisement}', [AdvertisementController::class, 'update']);
    Route::delete('advertisements/{advertisement}', [AdvertisementController::class, 'destroy']);

    // Exportación protegida (PDF/Excel completo)
    Route::get('tournaments/{tournament}/export/pdf', [ExportController::class, 'tournamentPdf']);
    Route::get('tournaments/{tournament}/export/excel', [ExportController::class, 'tournamentExcel']);
    Route::get('players/export/excel', [ExportController::class, 'playersExcel']);
});
