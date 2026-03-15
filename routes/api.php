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
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\SuperAdminController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\CanchaController;
use App\Http\Controllers\Api\ReservaController;
use App\Http\Controllers\Api\TeamPaymentController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\AffiliateController;
use App\Http\Controllers\Api\SuperAdminAffiliateController;
use App\Http\Controllers\Api\TournamentSlotReservationController;

/*
|--------------------------------------------------------------------------
| Rutas de Autenticación (sin tenant)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // Limit register/login to 10 attempts per minute per IP
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware('throttle:5,1')->group(function () {
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

/*
|--------------------------------------------------------------------------
| Rutas Públicas de Planes y Canchas (sin auth)
|--------------------------------------------------------------------------
*/
Route::get('plans', [SubscriptionController::class, 'plans']);
Route::get('tenants/public', [TenantController::class, 'publicList']);

/*
|--------------------------------------------------------------------------
| Webhooks (sin auth, sin CSRF)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function () {
    Route::post('mercadopago', [WebhookController::class, 'mercadopago']);
    Route::post('paypal', [WebhookController::class, 'paypal']);
});

/*
|--------------------------------------------------------------------------
| Rutas Públicas por Tenant (sin auth, tenant por slug)
|--------------------------------------------------------------------------
*/
Route::prefix('t/{tenant_slug}/public')->middleware('tenant')->group(function () {
    // Info del tenant
    Route::get('info', [TenantController::class, 'publicInfo']);

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

    // Exportación pública
    Route::get('tournaments/{tournament}/export/standings', [ExportController::class, 'standings']);
    Route::get('tournaments/{tournament}/export/fixture', [ExportController::class, 'fixture']);

    // Liguilla
    Route::get('tournaments/{tournament}/standings', [LiguillaController::class, 'standings']);
    Route::get('tournaments/{tournament}/phases', [LiguillaController::class, 'getPhases']);
    Route::get('phases/{phase}/bracket', [LiguillaController::class, 'getBracket']);
});

/*
|--------------------------------------------------------------------------
| Rutas Públicas legacy (sin tenant, compatibilidad temporal)
|--------------------------------------------------------------------------
*/
Route::prefix('public')->middleware('tenant')->group(function () {
    Route::get('players', [PlayerController::class, 'index']);
    Route::get('players/statistics/top', [PlayerController::class, 'statistics']);
    Route::get('players/{player}', [PlayerController::class, 'show']);

    Route::get('teams', [TeamController::class, 'index']);
    Route::get('teams/standings/table', [TeamController::class, 'standings']);
    Route::get('teams/{team}', [TeamController::class, 'show']);

    Route::get('matches', [MatchController::class, 'index']);
    Route::get('matches/upcoming/list', [MatchController::class, 'upcoming']);
    Route::get('matches/live/current', [MatchController::class, 'live']);
    Route::get('matches/{match}', [MatchController::class, 'show']);

    Route::get('tournaments', [TournamentController::class, 'index']);
    Route::get('tournaments/{tournament}', [TournamentController::class, 'show']);
    Route::get('tournaments/{tournament}/teams', [TournamentController::class, 'getTeams']);

    Route::get('advertisements', [AdvertisementController::class, 'active']);

    Route::get('tournaments/{tournament}/export/standings', [ExportController::class, 'standings']);
    Route::get('tournaments/{tournament}/export/fixture', [ExportController::class, 'fixture']);

    Route::get('tournaments/{tournament}/standings', [LiguillaController::class, 'standings']);
    Route::get('tournaments/{tournament}/phases', [LiguillaController::class, 'getPhases']);
    Route::get('phases/{phase}/bracket', [LiguillaController::class, 'getBracket']);
});

/*
|--------------------------------------------------------------------------
| Rutas Protegidas (auth + tenant por header X-Tenant-Id)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    // Tenant management
    Route::get('tenant', [TenantController::class, 'show']);
    Route::put('tenant', [TenantController::class, 'update']);
    Route::get('tenant/switch/{tenant}', [TenantController::class, 'switchTenant']);

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
    Route::post('tournaments', [TournamentController::class, 'store'])
        ->middleware('plan.limit:create_tournament');
    Route::put('tournaments/{tournament}', [TournamentController::class, 'update']);
    Route::delete('tournaments/{tournament}', [TournamentController::class, 'destroy']);

    // Gestión de equipos en torneo
    Route::post('tournaments/{tournament}/teams', [TournamentController::class, 'addTeams']);
    Route::post('tournaments/{tournament}/add-team', [TournamentController::class, 'addTeamToActiveTournament']);
    Route::delete('tournaments/{tournament}/teams/{team}', [TournamentController::class, 'removeTeam']);

    // Generación de fixture
    Route::post('tournaments/{tournament}/generate-fixture', [TournamentController::class, 'generateFixture']);
    Route::post('tournaments/{tournament}/regenerate-fixture', [TournamentController::class, 'regenerateFixture']);
    Route::post('tournaments/{tournament}/regenerate-fixture-from-matchday', [TournamentController::class, 'regenerateFixtureFromMatchday']);
    Route::post('tournaments/{tournament}/clone', [TournamentController::class, 'cloneTournament']);

    // Jornadas
    Route::post('tournaments/{tournament}/matchdays', [TournamentController::class, 'createMatchday']);
    Route::put('matchdays/{matchday}', [TournamentController::class, 'updateMatchday']);
    Route::post('matchdays/{matchday}/postpone', [TournamentController::class, 'postponeMatchday']);
    Route::delete('matchdays/{matchday}', [TournamentController::class, 'deleteMatchday']);

    // Partidos
    Route::post('matchdays/{matchday}/matches', [MatchController::class, 'store']);
    Route::post('matchdays/{matchday}/reorder', [MatchController::class, 'reorderMatches']);
    Route::put('matches/{match}', [MatchController::class, 'update']);
    Route::delete('matches/{match}', [MatchController::class, 'destroy']);
    Route::post('matches/{match}/events', [MatchController::class, 'addEvent']);
    Route::delete('matches/{match}/events/{event}', [MatchController::class, 'removeEvent']);
    Route::put('matches/{match}/status', [MatchController::class, 'updateStatus']);
    Route::put('matches/{match}/start', [MatchController::class, 'startMatch']);
    Route::put('matches/{match}/finish', [MatchController::class, 'finishMatch']);
    Route::put('matches/{match}/postpone', [MatchController::class, 'postponeMatch']);
    Route::put('matches/{match}/cancel', [MatchController::class, 'cancelMatch']);
    Route::get('matches/check-matchup', [MatchController::class, 'checkMatchup']);
    Route::get('matches/check-time-availability', [MatchController::class, 'checkTimeAvailability']);

    // Liguilla
    Route::post('tournaments/{tournament}/generate-liguilla', [LiguillaController::class, 'generateLiguilla']);
    Route::delete('tournaments/{tournament}/liguilla', [LiguillaController::class, 'deleteLiguilla']);
    Route::post('brackets/{bracket}/advance-winner', [LiguillaController::class, 'advanceWinner']);
    Route::post('brackets/{bracket}/set-winner', [LiguillaController::class, 'setWinner']);
    Route::post('phases/{phase}/create-next-round', [LiguillaController::class, 'createNextRoundMatches']);

    // Publicidad
    Route::get('advertisements', [AdvertisementController::class, 'index']);
    Route::post('advertisements', [AdvertisementController::class, 'store']);
    Route::put('advertisements/{advertisement}', [AdvertisementController::class, 'update']);
    Route::delete('advertisements/{advertisement}', [AdvertisementController::class, 'destroy']);
    Route::post('advertisements/{advertisement}/toggle', [AdvertisementController::class, 'toggleActive']);

    // Soporte
    Route::get('support-tickets', [SupportTicketController::class, 'index']);
    Route::post('support-tickets', [SupportTicketController::class, 'store']);
    Route::get('support-tickets/{ticket}', [SupportTicketController::class, 'show']);

    // Exportación protegida
    Route::get('tournaments/{tournament}/export/pdf', [ExportController::class, 'tournamentPdf'])
        ->middleware('plan.limit:export');
    Route::get('tournaments/{tournament}/export/excel', [ExportController::class, 'tournamentExcel'])
        ->middleware('plan.limit:export');
    Route::get('players/export/excel', [ExportController::class, 'playersExcel'])
        ->middleware('plan.limit:export');

    // Billing y Suscripciones (solo recurrentes)
    Route::prefix('billing')->group(function () {
        Route::get('current-plan', [SubscriptionController::class, 'currentPlan']);
        Route::post('cancel', [SubscriptionController::class, 'cancel']);
        Route::post('downgrade', [SubscriptionController::class, 'downgradeToFree']);
        Route::get('payments', [SubscriptionController::class, 'paymentHistory']);
        Route::post('subscribe/mercadopago', [SubscriptionController::class, 'subscribeMercadoPago']);
        Route::post('subscribe/paypal', [SubscriptionController::class, 'subscribePayPal']);
        Route::post('cancel-recurring', [SubscriptionController::class, 'cancelRecurring']);
    });

    // Gestión de usuarios del tenant
    Route::get('users', [UserManagementController::class, 'index']);
    Route::post('users', [UserManagementController::class, 'store']);
    Route::delete('users/{user}', [UserManagementController::class, 'destroy']);

    // Branding
    Route::prefix('branding')->group(function () {
        Route::get('/', [BrandingController::class, 'show']);
        Route::put('/', [BrandingController::class, 'update']);
        Route::post('logo', [BrandingController::class, 'uploadLogo']);
        Route::post('favicon', [BrandingController::class, 'uploadFavicon']);
        Route::post('cover', [BrandingController::class, 'uploadCover']);
        Route::delete('asset/{type}', [BrandingController::class, 'removeAsset']);
    });

    // Canchas y tarifas
    Route::get('canchas', [CanchaController::class, 'index']);
    Route::post('canchas', [CanchaController::class, 'store']);
    Route::put('canchas/{cancha}', [CanchaController::class, 'update']);
    Route::delete('canchas/{cancha}', [CanchaController::class, 'destroy']);
    Route::get('canchas/{cancha}/tarifas', [CanchaController::class, 'tarifas']);
    Route::post('canchas/{cancha}/tarifas', [CanchaController::class, 'storeTarifa']);
    Route::put('tarifas/{tarifa}', [CanchaController::class, 'updateTarifa']);
    Route::delete('tarifas/{tarifa}', [CanchaController::class, 'destroyTarifa']);

    // Reservas
    Route::get('reservas/calendar', [ReservaController::class, 'calendar']);
    Route::get('reservas/usage', [ReservaController::class, 'usage']);
    Route::post('reservas/precio', [ReservaController::class, 'calcularPrecio']);
    Route::get('reservas', [ReservaController::class, 'index']);
    Route::post('reservas', [ReservaController::class, 'store'])->middleware('plan.limit:create_reserva');
    Route::get('reservas/{reserva}', [ReservaController::class, 'show']);
    Route::put('reservas/{reserva}', [ReservaController::class, 'update']);
    Route::delete('reservas/{reserva}', [ReservaController::class, 'destroy']);

    // Turnos fijos (recurrencias)
    Route::get('recurrencias', [ReservaController::class, 'recurrencias']);
    Route::post('recurrencias', [ReservaController::class, 'storeRecurrencia'])->middleware('plan.limit:create_reserva');
    Route::put('recurrencias/{rec}', [ReservaController::class, 'updateRecurrencia']);
    Route::delete('recurrencias/{rec}', [ReservaController::class, 'destroyRecurrencia']);

    // Horarios fijos (slot reservations por equipo en torneo)
    Route::get('tournaments/{tournament}/slot-reservations', [TournamentSlotReservationController::class, 'index']);
    Route::post('tournaments/{tournament}/slot-reservations', [TournamentSlotReservationController::class, 'store']);
    Route::put('slot-reservations/{slotReservation}', [TournamentSlotReservationController::class, 'update']);
    Route::post('slot-reservations/{slotReservation}/mark-paid', [TournamentSlotReservationController::class, 'markPaid']);
    Route::delete('slot-reservations/{slotReservation}', [TournamentSlotReservationController::class, 'destroy']);

    // Pagos de equipos
    Route::get('team-payments/summary', [TeamPaymentController::class, 'summary']);
    Route::get('team-payments', [TeamPaymentController::class, 'index']);
    Route::post('team-payments', [TeamPaymentController::class, 'store']);
    Route::get('team-payments/{teamPayment}', [TeamPaymentController::class, 'show']);
    Route::put('team-payments/{teamPayment}', [TeamPaymentController::class, 'update']);
    Route::post('team-payments/{teamPayment}/mark-paid', [TeamPaymentController::class, 'markAsPaid']);
    Route::delete('team-payments/{teamPayment}', [TeamPaymentController::class, 'destroy']);

    // Dominios personalizados
    Route::prefix('domains')->group(function () {
        Route::get('/', [DomainController::class, 'index']);
        Route::post('/', [DomainController::class, 'store']);
        Route::post('{domain}/verify', [DomainController::class, 'verify']);
        Route::post('{domain}/set-active', [DomainController::class, 'setActive']);
        Route::delete('{domain}', [DomainController::class, 'destroy']);
    });

    // Afiliados (tenant)
    Route::prefix('affiliates')->group(function () {
        Route::get('/', [AffiliateController::class, 'show']);
        Route::post('/register', [AffiliateController::class, 'register']);
        Route::put('/payment-info', [AffiliateController::class, 'updatePaymentInfo']);
        Route::get('/commissions', [AffiliateController::class, 'commissions']);
        Route::post('/request-payout', [AffiliateController::class, 'requestPayout']);
    });
});

/*
|--------------------------------------------------------------------------
| Rutas SuperAdmin (auth + superadmin)
|--------------------------------------------------------------------------
*/
Route::prefix('superadmin')->middleware(['auth:sanctum', 'superadmin'])->group(function () {
    Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
    Route::get('tenants', [SuperAdminController::class, 'tenants']);
    Route::get('tenants/{tenant}', [SuperAdminController::class, 'tenantDetail']);
    Route::put('tenants/{tenant}', [SuperAdminController::class, 'updateTenant']);
    Route::put('tenants/{tenant}/change-plan', [SuperAdminController::class, 'changePlan']);
    Route::get('subscriptions', [SuperAdminController::class, 'subscriptions']);
    Route::get('payments', [SuperAdminController::class, 'payments']);
    // Planes de suscripción
    Route::get('plans', [PlanController::class, 'index']);
    Route::put('plans/{plan}', [PlanController::class, 'update']);
    // Soporte
    Route::get('support-tickets', [SupportTicketController::class, 'adminIndex']);
    Route::put('support-tickets/{ticket}', [SupportTicketController::class, 'adminUpdate']);

    // Afiliados (superadmin)
    Route::prefix('affiliates')->group(function () {
        Route::get('/', [SuperAdminAffiliateController::class, 'index']);
        Route::put('/{affiliate}', [SuperAdminAffiliateController::class, 'update']);
        Route::get('/{affiliate}/commissions', [SuperAdminAffiliateController::class, 'commissions']);
        Route::post('/{affiliate}/mark-paid', [SuperAdminAffiliateController::class, 'markPaid']);
    });
});
