<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTournamentRequest;
use App\Http\Requests\UpdateTournamentRequest;
use App\Http\Requests\GenerateFixtureRequest;
use App\Models\Tournament;
use App\Models\Matchday;
use App\Models\Matchs;
use App\Models\Team;
use App\Models\MatchdaySchedule;
use App\Models\MatchdayScheduleSlot;
use App\Models\TournamentSlotReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TournamentController extends Controller
{
    public function index(): JsonResponse
    {
        $tournaments = Tournament::with(['teams', 'matchdays.matches'])
            ->orderBy('start_date', 'desc')
            ->paginate(15);

        return response()->json($tournaments);
    }

    public function store(StoreTournamentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('banner')) {
            $validated['banner'] = $request->file('banner')->store('tournaments', 'public');
        }

        $tournament = Tournament::create($validated);

        return response()->json([
            'message' => 'Torneo creado exitosamente',
            'tournament' => $tournament
        ], 201);
    }

    public function show(Tournament $tournament): JsonResponse
    {
        $tournament->load([
            'teams',
            'matchdays.matches.homeTeam',
            'matchdays.matches.awayTeam',
            'schedules.slots'
        ]);

        $allTeamIds = $tournament->teams->pluck('id')->toArray();

        $tournament->matchdays->each(function ($matchday) use ($tournament, $allTeamIds) {
            if ($matchday->matches->isEmpty()) {
                $matchday->resting_teams = collect([]);
                return;
            }
            $activeTeamIds = $matchday->matches
                ->whereNotIn('status', ['Cancelado', 'Pospuesto'])
                ->flatMap(fn($m) => [$m->home_team_id, $m->away_team_id])
                ->unique()
                ->toArray();

            $matchday->resting_teams = $tournament->teams
                ->whereIn('id', array_diff($allTeamIds, $activeTeamIds))
                ->values();
        });

        return response()->json($tournament);
    }

    public function update(UpdateTournamentRequest $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('banner')) {
            $validated['banner'] = $request->file('banner')->store('tournaments', 'public');
        }

        $tournament->update($validated);

        return response()->json([
            'message' => 'Torneo actualizado exitosamente',
            'tournament' => $tournament->fresh()
        ]);
    }

    public function destroy(Tournament $tournament): JsonResponse
    {
        DB::beginTransaction();
        try {
            foreach ($tournament->matchdays as $matchday) {
                $matchday->matches()->delete();
            }
            $tournament->matchdays()->delete();

            DB::table('tournament_team')
                ->where('tournament_id', $tournament->id)
                ->delete();

            $tournament->delete();

            DB::commit();

            return response()->json([
                'message' => 'Torneo eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al eliminar el torneo',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function addTeams(Request $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validate([
            'team_ids' => 'required|array|min:1',
            'team_ids.*' => 'exists:teams,id'
        ]);

        $existingTeams = DB::table('tournament_team')
            ->where('tournament_id', $tournament->id)
            ->pluck('team_id')
            ->toArray();

        $newTeams = array_diff($validated['team_ids'], $existingTeams);

        if (empty($newTeams)) {
            return response()->json([
                'message' => 'Todos los equipos ya están en el torneo'
            ], 400);
        }

        // Verificar que ningún jugador activo de los equipos nuevos esté en otro equipo ya inscrito en este torneo
        $playerConflict = DB::table('player_team as pt_new')
            ->join('player_team as pt_existing', 'pt_new.player_id', '=', 'pt_existing.player_id')
            ->join('tournament_team', 'pt_existing.team_id', '=', 'tournament_team.team_id')
            ->join('teams as t_new', 'pt_new.team_id', '=', 't_new.id')
            ->join('teams as t_existing', 'pt_existing.team_id', '=', 't_existing.id')
            ->join('players', 'pt_new.player_id', '=', 'players.id')
            ->whereNull('pt_new.left_at')
            ->whereNull('pt_existing.left_at')
            ->whereIn('pt_new.team_id', $newTeams)
            ->where('tournament_team.tournament_id', $tournament->id)
            ->whereNotIn('pt_existing.team_id', $newTeams)
            ->select('players.name as player_name', 't_new.name as new_team_name', 't_existing.name as existing_team_name')
            ->first();

        if ($playerConflict) {
            return response()->json([
                'error' => "El jugador \"{$playerConflict->player_name}\" del equipo \"{$playerConflict->new_team_name}\" ya pertenece al equipo \"{$playerConflict->existing_team_name}\" en este torneo."
            ], 400);
        }

        DB::table('tournament_team')->insert(
            collect($newTeams)->map(function ($teamId) use ($tournament) {
                return [
                    'tournament_id' => $tournament->id,
                    'team_id' => $teamId,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            })->toArray()
        );

        return response()->json([
            'message' => count($newTeams) . ' equipo(s) agregado(s) al torneo',
            'teams_added' => $newTeams
        ]);
    }

    public function getTeams(Tournament $tournament): JsonResponse
    {
        $teams = Team::whereIn('id', function ($query) use ($tournament) {
            $query->select('team_id')
                ->from('tournament_team')
                ->where('tournament_id', $tournament->id);
        })->get();

        return response()->json($teams);
    }

    public function removeTeam(Request $request, Tournament $tournament, Team $team): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $inTournament = DB::table('tournament_team')
            ->where('tournament_id', $tournament->id)
            ->where('team_id', $team->id)
            ->exists();

        if (!$inTournament) {
            return response()->json(['error' => 'El equipo no está en este torneo'], 404);
        }

        // Eliminar solo los partidos NO finalizados del equipo en este torneo.
        // Los partidos ya jugados se conservan para que los rivales mantengan sus puntos y goles.
        $matchdayIds = $tournament->matchdays()->pluck('id');
        Matchs::whereIn('matchday_id', $matchdayIds)
            ->whereNotIn('status', ['Finalizado'])
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                  ->orWhere('away_team_id', $team->id);
            })
            ->delete();

        // Registrar la baja con justificación
        DB::table('tournament_withdrawals')->insert([
            'tournament_id' => $tournament->id,
            'team_id'       => $team->id,
            'reason'        => $request->reason,
            'withdrawn_at'  => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Remover del torneo
        DB::table('tournament_team')
            ->where('tournament_id', $tournament->id)
            ->where('team_id', $team->id)
            ->delete();

        return response()->json(['message' => 'Equipo dado de baja del torneo correctamente']);
    }

    public function generateFixture(GenerateFixtureRequest $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $teams = DB::table('tournament_team')
                ->where('tournament_id', $tournament->id)
                ->pluck('team_id')
                ->toArray();

            if (count($teams) < 2) {
                return response()->json([
                    'error' => 'Se necesitan al menos 2 equipos para generar el fixture'
                ], 400);
            }

            $matches = $this->generateRoundRobinMatches($teams, $validated['rounds']);

            $scheduleType = $validated['schedule_type'] ?? 'single_day';

            // Crear schedule para guardar la configuración
            $schedule = $this->createSchedule($tournament, $validated);

            if ($scheduleType === 'multi_day') {
                $result = $this->distributeMatchesMultiDay($tournament, $matches, $validated, $schedule);
            } else {
                $result = $this->distributeMatchesSingleDay($tournament, $matches, $validated, $schedule);
            }

            DB::commit();

            return response()->json([
                'message' => 'Fixture generado exitosamente',
                'matchdays_created' => $result['matchdays_created'],
                'total_matches' => $result['total_matches'],
                'schedule_type' => $scheduleType
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al generar el fixture',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un schedule y sus slots basado en la configuración
     */
    private function createSchedule(Tournament $tournament, array $config): MatchdaySchedule
    {
        $scheduleType = $config['schedule_type'] ?? 'single_day';

        $schedule = MatchdaySchedule::create([
            'tournament_id' => $tournament->id,
            'schedule_type' => $scheduleType,
            'name' => $scheduleType === 'multi_day' ? 'Horario Semanal' : 'Horario de Jornada'
        ]);

        if ($scheduleType === 'multi_day' && !empty($config['week_schedule'])) {
            $slotOrder = 0;
            foreach ($config['week_schedule'] as $dayConfig) {
                foreach ($dayConfig['time_slots'] as $slot) {
                    MatchdayScheduleSlot::create([
                        'matchday_schedule_id' => $schedule->id,
                        'day_of_week' => $dayConfig['day_of_week'],
                        'start_time' => $slot['start_time'],
                        'location' => $slot['location'] ?? $config['location'] ?? null,
                        'cancha_id' => $slot['cancha_id'] ?? null,
                        'max_matches' => $slot['matches_count'] ?? 1,
                        'slot_order' => $slotOrder++
                    ]);
                }
            }
        } elseif (!empty($config['time_slots'])) {
            // single_day con slots personalizados
            $slotOrder = 0;
            foreach ($config['time_slots'] as $slot) {
                MatchdayScheduleSlot::create([
                    'matchday_schedule_id' => $schedule->id,
                    'day_of_week' => null,
                    'start_time' => $slot['start_time'],
                    'location' => $slot['location'] ?? $config['location'] ?? null,
                    'cancha_id' => $slot['cancha_id'] ?? null,
                    'max_matches' => $slot['matches_count'] ?? 1,
                    'slot_order' => $slotOrder++
                ]);
            }
        } else {
            // single_day con match_time y matches_per_day (modo legacy)
            $matchTime = Carbon::parse($config['match_time']);
            $matchesPerDay = $config['matches_per_day'] ?? 4;

            for ($i = 0; $i < $matchesPerDay; $i++) {
                MatchdayScheduleSlot::create([
                    'matchday_schedule_id' => $schedule->id,
                    'day_of_week' => null,
                    'start_time' => $matchTime->format('H:i'),
                    'location' => $config['location'] ?? null,
                    'cancha_id' => $config['cancha_id'] ?? null,
                    'max_matches' => 1,
                    'slot_order' => $i
                ]);
                $matchTime->addMinutes(90);
            }
        }

        return $schedule;
    }

    /**
     * Distribuir partidos en modo single_day (un día por jornada)
     * Respeta la regla de máximo 2 partidos por equipo por jornada (solo si va atrasado)
     */
    private function distributeMatchesSingleDay(Tournament $tournament, array $matches, array $config, MatchdaySchedule $schedule): array
    {
        $slots = $schedule->slots()->orderBy('slot_order')->get();
        $maxMatchesPerMatchday = $slots->sum('max_matches');
        $reservations = $this->loadSlotReservations($tournament);

        // Agrupar partidos respetando el límite por equipo
        $teamTotalMatches = [];
        $matchdayGroups = $this->groupMatchesRespectingTeamLimit($matches, $maxMatchesPerMatchday, $teamTotalMatches);

        $startDate = Carbon::parse($config['start_date']);
        $matchDayOfWeek = isset($config['match_day_of_week']) ? (int) $config['match_day_of_week'] : null;

        // Snap al día de semana correcto desde el inicio
        if ($matchDayOfWeek !== null) {
            $this->snapToDayOfWeek($startDate, $matchDayOfWeek);
        }
        $matchdayNumber = 1;
        $totalMatches = 0;

        foreach ($matchdayGroups as $matchdayMatches) {
            $matchday = Matchday::create([
                'tournament_id' => $tournament->id,
                'number' => $matchdayNumber,
                'name' => "Jornada {$matchdayNumber}",
                'date' => $startDate->format('Y-m-d'),
                'matchday_schedule_id' => $schedule->id
            ]);

            // Pre-asignar partidos con horario fijo a sus slots preferidos
            $assigned = $this->assignMatchesToSlotBuckets($matchdayMatches, $slots, $reservations);
            $freeMatches = $assigned['free'];

            foreach ($slots->values() as $i => $slot) {
                $slotMatches = $assigned['buckets'][$i] ?? [];
                $remaining   = $assigned['capacity'][$i] ?? $slot->max_matches;

                // Primero los reservados
                foreach ($slotMatches as $match) {
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $startDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }

                // Luego llenar con partidos libres
                for ($m = 0; $m < $remaining && !empty($freeMatches); $m++) {
                    $match = array_shift($freeMatches);
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $startDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }
            }

            $startDate->addDays($config['days_between_matchdays']);
            if ($matchDayOfWeek !== null) {
                $this->snapToDayOfWeek($startDate, $matchDayOfWeek);
            }
            $matchdayNumber++;
        }

        return [
            'matchdays_created' => count($matchdayGroups),
            'total_matches' => $totalMatches
        ];
    }

    /**
     * Distribuir partidos en modo multi_day (varios días por jornada)
     * Respeta la regla de máximo 2 partidos por equipo por jornada (solo si va atrasado)
     */
    private function distributeMatchesMultiDay(Tournament $tournament, array $matches, array $config, MatchdaySchedule $schedule): array
    {
        $slots = $schedule->slots()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('slot_order')
            ->get();

        // Calcular capacidad total de partidos por semana
        $matchesPerWeek = $slots->sum('max_matches');

        if ($matchesPerWeek === 0) {
            throw new \Exception('No hay slots configurados para distribuir partidos');
        }

        // Agrupar partidos respetando el límite por equipo
        $teamTotalMatches = [];
        $matchdayGroups = $this->groupMatchesRespectingTeamLimit($matches, $matchesPerWeek, $teamTotalMatches);
        $reservations = $this->loadSlotReservations($tournament);

        $startDate = Carbon::parse($config['start_date']);

        // Encontrar el lunes de la semana de inicio
        $weekStart = $startDate->copy()->startOfWeek(Carbon::MONDAY);
        $matchdayNumber = 1;
        $matchdaysCreated = 0;
        $totalMatches = 0;

        foreach ($matchdayGroups as $weekMatches) {
            // Calcular fecha inicio y fin de la jornada
            $daysInWeek = $slots->pluck('day_of_week')->unique()->sort();
            $firstDay = $daysInWeek->first();
            $lastDay = $daysInWeek->last();

            // Calcular fecha real del primer y último día
            $matchdayStartDate = $this->getDateForDayOfWeek($weekStart, $firstDay);
            $matchdayEndDate = $this->getDateForDayOfWeek($weekStart, $lastDay);

            $matchday = Matchday::create([
                'tournament_id' => $tournament->id,
                'number' => $matchdayNumber,
                'name' => "Jornada {$matchdayNumber}",
                'date' => $matchdayStartDate->format('Y-m-d'),
                'end_date' => $matchdayEndDate->format('Y-m-d'),
                'matchday_schedule_id' => $schedule->id
            ]);

            // Pre-asignar partidos con horario fijo (multi_day: se considera día + hora)
            $assigned = $this->assignMatchesToSlotBuckets($weekMatches, $slots, $reservations);
            $freeMatches = $assigned['free'];

            foreach ($slots->values() as $i => $slot) {
                $slotDate    = $this->getDateForDayOfWeek($weekStart, $slot->day_of_week);
                $slotMatches = $assigned['buckets'][$i] ?? [];
                $remaining   = $assigned['capacity'][$i] ?? $slot->max_matches;

                foreach ($slotMatches as $match) {
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $slotDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }

                for ($m = 0; $m < $remaining && !empty($freeMatches); $m++) {
                    $match = array_shift($freeMatches);
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $slotDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }
            }

            $weekStart->addDays($config['days_between_matchdays']);
            $matchdayNumber++;
            $matchdaysCreated++;
        }

        return [
            'matchdays_created' => $matchdaysCreated,
            'total_matches' => $totalMatches
        ];
    }

    /**
     * Carga los horarios fijos (slot reservations) del torneo como mapa team_id => reservation.
     */
    private function loadSlotReservations(Tournament $tournament): array
    {
        return TournamentSlotReservation::where('tournament_id', $tournament->id)
            ->get()
            ->keyBy('team_id')
            ->all();
    }

    /**
     * Distribuye los partidos de una jornada en buckets por slot, respetando horarios fijos.
     *
     * Devuelve un array indexado igual que $slots donde cada elemento es un array de matches
     * asignados a ese slot. Los partidos sin preferencia se acumulan en $freeMatches (por ref).
     *
     * @param  array  $matchdayMatches  Lista de ['home'=>id,'away'=>id]
     * @param  \Illuminate\Support\Collection  $slots  Colección de MatchdayScheduleSlot
     * @param  array  $reservations  Mapa team_id=>TournamentSlotReservation
     * @param  int|null  $filterDayOfWeek  Si se pasa, los slots se filtran a ese día (multi_day, slot por slot)
     * @return array  ['buckets' => [slotIndex => [matches]], 'free' => [matches]]
     */
    private function assignMatchesToSlotBuckets(
        array $matchdayMatches,
        $slots,
        array $reservations,
        ?int $filterDayOfWeek = null
    ): array {
        // Construir índice de capacidad por slot
        $buckets   = [];
        $capacity  = [];
        $slotsList = $slots->values(); // índices 0,1,2...

        foreach ($slotsList as $i => $slot) {
            // En multi_day sólo consideramos los slots de este día
            if ($filterDayOfWeek !== null && $slot->day_of_week !== $filterDayOfWeek) {
                continue;
            }
            $buckets[$i]  = [];
            $capacity[$i] = $slot->max_matches;
        }

        $freeMatches = [];

        foreach ($matchdayMatches as $match) {
            $homeId = $match['home'];
            $awayId = $match['away'];

            // El home tiene prioridad; si no, revisar away
            $reservation = $reservations[$homeId] ?? $reservations[$awayId] ?? null;

            $placed = false;
            if ($reservation !== null) {
                // Buscar un slot que coincida con la reserva
                foreach ($slotsList as $i => $slot) {
                    if (!isset($capacity[$i])) continue; // slot filtrado
                    if ($capacity[$i] <= 0) continue;

                    $slotDay = $filterDayOfWeek ?? $slot->day_of_week;
                    if ($reservation->matchesSlot($slot->start_time, $slotDay)) {
                        $buckets[$i][] = $match;
                        $capacity[$i]--;
                        $placed = true;
                        break;
                    }
                }
            }

            if (!$placed) {
                $freeMatches[] = $match;
            }
        }

        return ['buckets' => $buckets, 'free' => $freeMatches, 'capacity' => $capacity, 'slots' => $slotsList];
    }

    /**
     * Avanza $date al próximo (o actual) día de semana $targetDow.
     * Si ya está en ese día, no la modifica.
     * Carbon: 0=Domingo, 1=Lunes … 6=Sábado
     */
    private function snapToDayOfWeek(Carbon $date, int $targetDow): void
    {
        $diff = $targetDow - $date->dayOfWeek;
        if ($diff < 0) $diff += 7;
        if ($diff > 0) $date->addDays($diff);
    }

    /**
     * Obtener la fecha real para un día de semana dado, basado en una semana de referencia
     */
    private function getDateForDayOfWeek(Carbon $weekStart, int $dayOfWeek): Carbon
    {
        // Carbon: 0=Sunday, 1=Monday...6=Saturday
        $currentDow = $weekStart->dayOfWeek;
        $diff = $dayOfWeek - $currentDow;

        if ($diff < 0) {
            $diff += 7;
        }

        return $weekStart->copy()->addDays($diff);
    }

    private function generateRoundRobinMatches(array $teams, int $rounds = 1): array
    {
        $teamCount = count($teams);
        $matches = [];

        if ($teamCount % 2 !== 0) {
            $teams[] = null;
            $teamCount++;
        }

        for ($round = 0; $round < $rounds; $round++) {
            for ($i = 0; $i < $teamCount - 1; $i++) {
                for ($j = 0; $j < $teamCount / 2; $j++) {
                    $home = ($i + $j) % ($teamCount - 1);
                    $away = ($teamCount - 1 - $j + $i) % ($teamCount - 1);

                    if ($j == 0) {
                        $away = $teamCount - 1;
                    }

                    if ($round % 2 == 1) {
                        $temp = $home;
                        $home = $away;
                        $away = $temp;
                    }

                    if ($teams[$home] !== null && $teams[$away] !== null) {
                        $matches[] = [
                            'home' => $teams[$home],
                            'away' => $teams[$away]
                        ];
                    }
                }
            }
        }

        return $matches;
    }

    public function regenerateFixture(GenerateFixtureRequest $request, Tournament $tournament): JsonResponse
    {
        try {
            DB::beginTransaction();

            foreach ($tournament->matchdays as $matchday) {
                $matchday->matches()->delete();
                $matchday->delete();
            }

            DB::commit();

            // Recalculate player and team stats from remaining events/matches
            $this->recalculateStatsForTournament($tournament);

            return $this->generateFixture($request, $tournament);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al regenerar el fixture',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recalculate player and team stats from remaining match_events/matches.
     * Called after regenerating a fixture to ensure global counters are accurate.
     */
    private function recalculateStatsForTournament(Tournament $tournament): void
    {
        $teamIds = $tournament->teams()->pluck('teams.id');

        // Player stats: recalculate from all remaining match_events for players in these teams
        $playerIds = DB::table('player_team')
            ->whereIn('team_id', $teamIds)
            ->pluck('player_id')
            ->unique();

        foreach ($playerIds as $playerId) {
            $goals = DB::table('match_events')
                ->where('player_id', $playerId)
                ->where('event_type', 'Gol')
                ->count();
            $assists = DB::table('match_events')
                ->where('player_id', $playerId)
                ->where('event_type', 'Asistencia')
                ->count();
            $yellowCards = DB::table('match_events')
                ->where('player_id', $playerId)
                ->where('event_type', 'Tarjeta Amarilla')
                ->count();
            $redCards = DB::table('match_events')
                ->where('player_id', $playerId)
                ->where('event_type', 'Tarjeta Roja')
                ->count();

            DB::table('players')->where('id', $playerId)->update([
                'goals'        => $goals,
                'assists'      => $assists,
                'yellow_cards' => $yellowCards,
                'red_cards'    => $redCards,
            ]);
        }

        // Team stats: recalculate from all remaining finished matches
        foreach ($teamIds as $teamId) {
            $homeMatches = DB::table('matches')
                ->where('home_team_id', $teamId)
                ->where('status', 'Finalizado')
                ->get(['home_score', 'away_score']);

            $awayMatches = DB::table('matches')
                ->where('away_team_id', $teamId)
                ->where('status', 'Finalizado')
                ->get(['home_score', 'away_score']);

            $wins = 0; $draws = 0; $losses = 0; $goalsFor = 0; $goalsAgainst = 0;

            foreach ($homeMatches as $m) {
                $goalsFor      += $m->home_score ?? 0;
                $goalsAgainst  += $m->away_score ?? 0;
                if ($m->home_score > $m->away_score)      $wins++;
                elseif ($m->home_score == $m->away_score) $draws++;
                else                                       $losses++;
            }
            foreach ($awayMatches as $m) {
                $goalsFor      += $m->away_score ?? 0;
                $goalsAgainst  += $m->home_score ?? 0;
                if ($m->away_score > $m->home_score)      $wins++;
                elseif ($m->home_score == $m->away_score) $draws++;
                else                                       $losses++;
            }

            DB::table('teams')->where('id', $teamId)->update([
                'matches_played' => $homeMatches->count() + $awayMatches->count(),
                'wins'           => $wins,
                'draws'          => $draws,
                'losses'         => $losses,
                'goals_for'      => $goalsFor,
                'goals_against'  => $goalsAgainst,
                'points'         => ($wins * 3) + $draws,
            ]);
        }
    }

    /**
     * Regenerar fixture desde una jornada específica
     * Preserva las jornadas anteriores y regenera desde la jornada indicada
     */
    public function regenerateFixtureFromMatchday(GenerateFixtureRequest $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validated();
        $fromMatchdayNumber = $validated['from_matchday'] ?? 1;

        try {
            DB::beginTransaction();

            $teams = DB::table('tournament_team')
                ->where('tournament_id', $tournament->id)
                ->pluck('team_id')
                ->toArray();

            if (count($teams) < 2) {
                return response()->json([
                    'error' => 'Se necesitan al menos 2 equipos para regenerar el fixture'
                ], 400);
            }

            // Obtener jornadas a eliminar (desde from_matchday en adelante)
            $matchdaysToDelete = Matchday::where('tournament_id', $tournament->id)
                ->where('number', '>=', $fromMatchdayNumber)
                ->where(function ($q) {
                    $q->where('phase_type', 'regular')
                      ->orWhereNull('phase_type');
                })
                ->get();

            // Obtener partidos ya jugados (de jornadas anteriores a from_matchday)
            $playedMatches = [];
            $preservedMatchdays = Matchday::where('tournament_id', $tournament->id)
                ->where('number', '<', $fromMatchdayNumber)
                ->where(function ($q) {
                    $q->where('phase_type', 'regular')
                      ->orWhereNull('phase_type');
                })
                ->with('matches')
                ->get();

            foreach ($preservedMatchdays as $matchday) {
                foreach ($matchday->matches as $match) {
                    $key = min($match->home_team_id, $match->away_team_id) . '-' . max($match->home_team_id, $match->away_team_id);
                    if (!isset($playedMatches[$key])) {
                        $playedMatches[$key] = 0;
                    }
                    $playedMatches[$key]++;
                }
            }

            // Eliminar jornadas desde from_matchday
            $matchdaysDeletedCount = 0;
            $matchesDeletedCount = 0;
            foreach ($matchdaysToDelete as $matchday) {
                $matchesDeletedCount += $matchday->matches()->count();
                $matchday->matches()->delete();
                $matchday->delete();
                $matchdaysDeletedCount++;
            }

            // Generar todos los partidos posibles según el round robin
            $rounds = $validated['rounds'] ?? 1;
            $allMatches = $this->generateRoundRobinMatches($teams, $rounds);

            // Filtrar partidos que ya se jugaron
            $remainingMatches = [];
            $matchCountByPair = $playedMatches;

            foreach ($allMatches as $match) {
                $key = min($match['home'], $match['away']) . '-' . max($match['home'], $match['away']);

                if (!isset($matchCountByPair[$key])) {
                    $matchCountByPair[$key] = 0;
                }

                // Determinar cuántos partidos debería haber entre estos equipos
                $expectedMatches = $rounds;

                if ($matchCountByPair[$key] < $expectedMatches) {
                    $remainingMatches[] = $match;
                    $matchCountByPair[$key]++;
                }
            }

            if (empty($remainingMatches)) {
                DB::commit();
                return response()->json([
                    'message' => 'No hay partidos pendientes por generar',
                    'matchdays_deleted' => $matchdaysDeletedCount,
                    'matches_deleted' => $matchesDeletedCount,
                    'matchdays_created' => 0,
                    'total_matches' => 0
                ]);
            }

            // Crear schedule para los nuevos partidos
            $schedule = $this->createSchedule($tournament, $validated);

            // Obtener la fecha de la jornada from_matchday o usar start_date
            $lastPreservedMatchday = $preservedMatchdays->last();
            if ($lastPreservedMatchday && !isset($validated['start_date'])) {
                $startDate = Carbon::parse($lastPreservedMatchday->date)->addDays($validated['days_between_matchdays']);
            } else {
                $startDate = Carbon::parse($validated['start_date']);
            }

            // Actualizar el start_date en validated para usar en la distribución
            $validated['start_date'] = $startDate->format('Y-m-d');

            // Calcular cuántos partidos tiene cada equipo en las jornadas preservadas
            $existingTeamMatchCounts = [];
            foreach ($preservedMatchdays as $md) {
                $matches = Matchs::where('matchday_id', $md->id)->get();
                foreach ($matches as $match) {
                    if (!isset($existingTeamMatchCounts[$match->home_team_id])) {
                        $existingTeamMatchCounts[$match->home_team_id] = 0;
                    }
                    if (!isset($existingTeamMatchCounts[$match->away_team_id])) {
                        $existingTeamMatchCounts[$match->away_team_id] = 0;
                    }
                    $existingTeamMatchCounts[$match->home_team_id]++;
                    $existingTeamMatchCounts[$match->away_team_id]++;
                }
            }

            // Distribuir los partidos restantes
            $scheduleType = $validated['schedule_type'] ?? 'single_day';
            $startingMatchdayNumber = $fromMatchdayNumber;

            if ($scheduleType === 'multi_day') {
                $result = $this->distributeMatchesMultiDayFromNumber($tournament, $remainingMatches, $validated, $schedule, $startingMatchdayNumber, $existingTeamMatchCounts);
            } else {
                $result = $this->distributeMatchesSingleDayFromNumber($tournament, $remainingMatches, $validated, $schedule, $startingMatchdayNumber, $existingTeamMatchCounts);
            }

            DB::commit();

            return response()->json([
                'message' => 'Fixture regenerado exitosamente desde la jornada ' . $fromMatchdayNumber,
                'matchdays_deleted' => $matchdaysDeletedCount,
                'matches_deleted' => $matchesDeletedCount,
                'matchdays_created' => $result['matchdays_created'],
                'total_matches' => $result['total_matches'],
                'preserved_matchdays' => $fromMatchdayNumber - 1
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al regenerar el fixture',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Distribuir partidos en modo single_day comenzando desde un número de jornada específico
     * Respeta la regla de máximo 2 partidos por equipo por jornada (solo si va atrasado)
     *
     * @param array $existingTeamMatchCounts Partidos ya jugados por equipo (de jornadas preservadas)
     */
    private function distributeMatchesSingleDayFromNumber(Tournament $tournament, array $matches, array $config, MatchdaySchedule $schedule, int $startingMatchdayNumber, array $existingTeamMatchCounts = []): array
    {
        $slots = $schedule->slots()->orderBy('slot_order')->get();
        $maxMatchesPerMatchday = $slots->sum('max_matches');
        $reservations = $this->loadSlotReservations($tournament);

        // Agrupar partidos respetando el límite por equipo, considerando partidos ya jugados
        $teamTotalMatches = $existingTeamMatchCounts;
        $matchdayGroups = $this->groupMatchesRespectingTeamLimit($matches, $maxMatchesPerMatchday, $teamTotalMatches);

        $startDate = Carbon::parse($config['start_date']);
        $matchDayOfWeek = isset($config['match_day_of_week']) ? (int) $config['match_day_of_week'] : null;
        if ($matchDayOfWeek !== null) {
            $this->snapToDayOfWeek($startDate, $matchDayOfWeek);
        }
        $matchdayNumber = $startingMatchdayNumber;
        $totalMatches = 0;

        foreach ($matchdayGroups as $matchdayMatches) {
            $matchday = Matchday::create([
                'tournament_id' => $tournament->id,
                'number' => $matchdayNumber,
                'name' => "Jornada {$matchdayNumber}",
                'date' => $startDate->format('Y-m-d'),
                'matchday_schedule_id' => $schedule->id
            ]);

            $assigned    = $this->assignMatchesToSlotBuckets($matchdayMatches, $slots, $reservations);
            $freeMatches = $assigned['free'];

            foreach ($slots->values() as $i => $slot) {
                $slotMatches = $assigned['buckets'][$i] ?? [];
                $remaining   = $assigned['capacity'][$i] ?? $slot->max_matches;

                foreach ($slotMatches as $match) {
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $startDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }

                for ($m = 0; $m < $remaining && !empty($freeMatches); $m++) {
                    $match = array_shift($freeMatches);
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $startDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }
            }

            $startDate->addDays($config['days_between_matchdays']);
            if ($matchDayOfWeek !== null) {
                $this->snapToDayOfWeek($startDate, $matchDayOfWeek);
            }
            $matchdayNumber++;
        }

        return [
            'matchdays_created' => count($matchdayGroups),
            'total_matches' => $totalMatches
        ];
    }

    /**
     * Agrupa partidos en jornadas respetando las reglas:
     * - Un equipo normalmente juega 1 vez por jornada
     * - Un equipo puede jugar 2 veces SOLO si va atrasado en partidos totales
     *
     * @param array $matches Lista de partidos a distribuir
     * @param int $maxMatchesPerMatchday Capacidad máxima de la jornada
     * @param array $teamTotalMatches Contador de partidos totales por equipo (se actualiza)
     */
    private function groupMatchesRespectingTeamLimit(array $matches, int $maxMatchesPerMatchday, array &$teamTotalMatches = []): array
    {
        $matchdayGroups = [];
        $currentMatchday = [];
        $teamMatchCountInMatchday = []; // Contador de partidos por equipo en la jornada actual

        // Calcular el máximo de partidos que tiene cualquier equipo
        $maxTotalMatches = !empty($teamTotalMatches) ? max($teamTotalMatches) : 0;

        foreach ($matches as $match) {
            $homeTeam = $match['home'];
            $awayTeam = $match['away'];

            // Inicializar contadores si no existen
            if (!isset($teamMatchCountInMatchday[$homeTeam])) {
                $teamMatchCountInMatchday[$homeTeam] = 0;
            }
            if (!isset($teamMatchCountInMatchday[$awayTeam])) {
                $teamMatchCountInMatchday[$awayTeam] = 0;
            }
            if (!isset($teamTotalMatches[$homeTeam])) {
                $teamTotalMatches[$homeTeam] = 0;
            }
            if (!isset($teamTotalMatches[$awayTeam])) {
                $teamTotalMatches[$awayTeam] = 0;
            }

            // Determinar el límite para cada equipo:
            // - Si va atrasado (menos partidos que el máximo), puede jugar hasta 2 veces
            // - Si está al día, solo puede jugar 1 vez
            $homeLimitInMatchday = ($teamTotalMatches[$homeTeam] < $maxTotalMatches) ? 2 : 1;
            $awayLimitInMatchday = ($teamTotalMatches[$awayTeam] < $maxTotalMatches) ? 2 : 1;

            // Verificar si podemos agregar este partido a la jornada actual
            $canAddToCurrentMatchday =
                count($currentMatchday) < $maxMatchesPerMatchday &&
                $teamMatchCountInMatchday[$homeTeam] < $homeLimitInMatchday &&
                $teamMatchCountInMatchday[$awayTeam] < $awayLimitInMatchday;

            if ($canAddToCurrentMatchday) {
                // Agregar a la jornada actual
                $currentMatchday[] = $match;
                $teamMatchCountInMatchday[$homeTeam]++;
                $teamMatchCountInMatchday[$awayTeam]++;
                $teamTotalMatches[$homeTeam]++;
                $teamTotalMatches[$awayTeam]++;

                // Recalcular el máximo después de cada partido agregado
                $maxTotalMatches = max($teamTotalMatches);
            } else {
                // Guardar la jornada actual y empezar una nueva
                if (!empty($currentMatchday)) {
                    $matchdayGroups[] = $currentMatchday;
                }
                $currentMatchday = [$match];
                $teamMatchCountInMatchday = [
                    $homeTeam => 1,
                    $awayTeam => 1
                ];
                $teamTotalMatches[$homeTeam]++;
                $teamTotalMatches[$awayTeam]++;

                // Recalcular el máximo
                $maxTotalMatches = max($teamTotalMatches);
            }
        }

        // No olvidar la última jornada
        if (!empty($currentMatchday)) {
            $matchdayGroups[] = $currentMatchday;
        }

        return $matchdayGroups;
    }

    /**
     * Distribuir partidos en modo multi_day comenzando desde un número de jornada específico
     * Respeta la regla de máximo 2 partidos por equipo por jornada (solo si va atrasado)
     *
     * @param array $existingTeamMatchCounts Partidos ya jugados por equipo (de jornadas preservadas)
     */
    private function distributeMatchesMultiDayFromNumber(Tournament $tournament, array $matches, array $config, MatchdaySchedule $schedule, int $startingMatchdayNumber, array $existingTeamMatchCounts = []): array
    {
        $slots = $schedule->slots()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('slot_order')
            ->get();

        $matchesPerWeek = $slots->sum('max_matches');

        if ($matchesPerWeek === 0) {
            throw new \Exception('No hay slots configurados para distribuir partidos');
        }

        // Agrupar partidos respetando el límite por equipo, considerando partidos ya jugados
        $teamTotalMatches = $existingTeamMatchCounts;
        $matchdayGroups = $this->groupMatchesRespectingTeamLimit($matches, $matchesPerWeek, $teamTotalMatches);
        $reservations = $this->loadSlotReservations($tournament);

        $startDate = Carbon::parse($config['start_date']);
        $weekStart = $startDate->copy()->startOfWeek(Carbon::MONDAY);
        $matchdayNumber = $startingMatchdayNumber;
        $matchdaysCreated = 0;
        $totalMatches = 0;

        foreach ($matchdayGroups as $weekMatches) {
            $daysInWeek = $slots->pluck('day_of_week')->unique()->sort();
            $firstDay = $daysInWeek->first();
            $lastDay = $daysInWeek->last();

            $matchdayStartDate = $this->getDateForDayOfWeek($weekStart, $firstDay);
            $matchdayEndDate = $this->getDateForDayOfWeek($weekStart, $lastDay);

            $matchday = Matchday::create([
                'tournament_id' => $tournament->id,
                'number' => $matchdayNumber,
                'name' => "Jornada {$matchdayNumber}",
                'date' => $matchdayStartDate->format('Y-m-d'),
                'end_date' => $matchdayEndDate->format('Y-m-d'),
                'matchday_schedule_id' => $schedule->id
            ]);

            $assigned    = $this->assignMatchesToSlotBuckets($weekMatches, $slots, $reservations);
            $freeMatches = $assigned['free'];

            foreach ($slots->values() as $i => $slot) {
                $slotDate    = $this->getDateForDayOfWeek($weekStart, $slot->day_of_week);
                $slotMatches = $assigned['buckets'][$i] ?? [];
                $remaining   = $assigned['capacity'][$i] ?? $slot->max_matches;

                foreach ($slotMatches as $match) {
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $slotDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }

                for ($m = 0; $m < $remaining && !empty($freeMatches); $m++) {
                    $match = array_shift($freeMatches);
                    Matchs::create([
                        'matchday_id'  => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date'   => $slotDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location'     => $slot->location ?? $config['location'] ?? null,
                        'cancha_id'    => $slot->cancha_id ?? null,
                        'home_score'   => 0,
                        'away_score'   => 0,
                        'status'       => 'Programado',
                    ]);
                    $totalMatches++;
                }
            }

            $weekStart->addDays($config['days_between_matchdays']);
            $matchdayNumber++;
            $matchdaysCreated++;
        }

        return [
            'matchdays_created' => $matchdaysCreated,
            'total_matches' => $totalMatches
        ];
    }

    public function createMatchday(Request $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'date' => 'required|date'
        ]);

        $lastMatchday = Matchday::where('tournament_id', $tournament->id)
            ->orderBy('number', 'desc')
            ->first();

        $number = $lastMatchday ? $lastMatchday->number + 1 : 1;

        $matchday = Matchday::create([
            'tournament_id' => $tournament->id,
            'number' => $number,
            'name' => $validated['name'] ?? "Jornada {$number}",
            'date' => $validated['date']
        ]);

        return response()->json([
            'message' => 'Jornada creada exitosamente',
            'matchday' => $matchday
        ], 201);
    }

    public function updateMatchday(Request $request, Matchday $matchday): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'date' => 'sometimes|date'
        ]);

        $matchday->update($validated);

        return response()->json([
            'message' => 'Jornada actualizada exitosamente',
            'matchday' => $matchday->fresh()
        ]);
    }

    public function postponeMatchday(Request $request, Matchday $matchday): JsonResponse
    {
        $validated = $request->validate([
            'new_date'  => 'required|date',
            'reason'    => 'nullable|string|max:255',
            'mode'      => 'required|in:cascade,manual',
        ]);

        $originalDate = Carbon::parse($matchday->date);
        $newDate      = Carbon::parse($validated['new_date']);
        $offsetDays   = $originalDate->diffInDays($newDate, false); // puede ser negativo

        DB::beginTransaction();
        try {
            // Guardar fecha original solo la primera vez que se pospone
            $matchday->update([
                'postponed_from'       => $matchday->postponed_from ?? $originalDate->toDateString(),
                'postponement_reason'  => $validated['reason'] ?? null,
                'date'                 => $newDate->toDateString(),
                'end_date'             => $matchday->end_date
                    ? Carbon::parse($matchday->end_date)->addDays($offsetDays)->toDateString()
                    : null,
            ]);

            // Mover match_dates de los partidos de esta jornada
            foreach ($matchday->matches as $match) {
                if ($match->match_date) {
                    $match->update([
                        'match_date' => Carbon::parse($match->match_date)->addDays($offsetDays),
                    ]);
                }
            }

            // Cascada: mover todas las jornadas siguientes (mayor número) del mismo torneo
            if ($validated['mode'] === 'cascade') {
                $following = Matchday::where('tournament_id', $matchday->tournament_id)
                    ->where('number', '>', $matchday->number)
                    ->orderBy('number')
                    ->with('matches')
                    ->get();

                foreach ($following as $next) {
                    $next->update([
                        'date'     => Carbon::parse($next->date)->addDays($offsetDays)->toDateString(),
                        'end_date' => $next->end_date
                            ? Carbon::parse($next->end_date)->addDays($offsetDays)->toDateString()
                            : null,
                    ]);

                    foreach ($next->matches as $match) {
                        if ($match->match_date) {
                            $match->update([
                                'match_date' => Carbon::parse($match->match_date)->addDays($offsetDays),
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message'           => $validated['mode'] === 'cascade'
                    ? 'Jornada reprogramada y jornadas siguientes recorridas exitosamente'
                    : 'Jornada reprogramada exitosamente',
                'offset_days'       => $offsetDays,
                'matchdays_updated' => $validated['mode'] === 'cascade'
                    ? Matchday::where('tournament_id', $matchday->tournament_id)
                        ->where('number', '>=', $matchday->number)
                        ->count()
                    : 1,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al reprogramar: ' . $e->getMessage()], 500);
        }
    }

    public function deleteMatchday(Matchday $matchday): JsonResponse
    {
        DB::beginTransaction();
        try {
            $matchday->matches()->delete();
            $matchday->delete();

            DB::commit();

            return response()->json([
                'message' => 'Jornada eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al eliminar la jornada',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function addTeamToActiveTournament(Request $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:teams,id',
            'generate_matches' => 'boolean',
            'start_date' => 'nullable|date',
            'match_time' => 'nullable|string',
            'days_between_matchdays' => 'nullable|integer|min:1'
        ]);

        try {
            DB::beginTransaction();

            $newTeamId = $validated['team_id'];

            $exists = DB::table('tournament_team')
                ->where('tournament_id', $tournament->id)
                ->where('team_id', $newTeamId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'error' => 'El equipo ya está en el torneo'
                ], 400);
            }

            // Verificar que ningún jugador activo del equipo nuevo esté en otro equipo ya inscrito en este torneo
            $playerConflict = DB::table('player_team as pt_new')
                ->join('player_team as pt_existing', 'pt_new.player_id', '=', 'pt_existing.player_id')
                ->join('tournament_team', 'pt_existing.team_id', '=', 'tournament_team.team_id')
                ->join('teams as t_existing', 'pt_existing.team_id', '=', 't_existing.id')
                ->join('players', 'pt_new.player_id', '=', 'players.id')
                ->whereNull('pt_new.left_at')
                ->whereNull('pt_existing.left_at')
                ->where('pt_new.team_id', $newTeamId)
                ->where('tournament_team.tournament_id', $tournament->id)
                ->where('pt_existing.team_id', '!=', $newTeamId)
                ->select('players.name as player_name', 't_existing.name as existing_team_name')
                ->first();

            if ($playerConflict) {
                return response()->json([
                    'error' => "El jugador \"{$playerConflict->player_name}\" ya pertenece al equipo \"{$playerConflict->existing_team_name}\" en este torneo."
                ], 400);
            }

            // Agregar equipo al torneo
            DB::table('tournament_team')->insert([
                'tournament_id' => $tournament->id,
                'team_id' => $newTeamId,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $matchesCreated = 0;
            $matchdaysCreated = 0;

            if ($validated['generate_matches'] ?? false) {
                // Obtener equipos existentes (sin el nuevo)
                $existingTeamIds = DB::table('tournament_team')
                    ->where('tournament_id', $tournament->id)
                    ->where('team_id', '!=', $newTeamId)
                    ->pluck('team_id')
                    ->toArray();

                if (count($existingTeamIds) === 0) {
                    DB::commit();
                    return response()->json([
                        'message' => 'Equipo agregado. No hay otros equipos para generar partidos.',
                        'matches_created' => 0,
                        'matchdays_created' => 0
                    ]);
                }

                // Contar partidos jugados por cada equipo en este torneo
                $matchesPerTeam = [];
                foreach ($existingTeamIds as $teamId) {
                    $count = Matchs::whereHas('matchday', function ($q) use ($tournament) {
                        $q->where('tournament_id', $tournament->id);
                    })->where(function ($q) use ($teamId) {
                        $q->where('home_team_id', $teamId)
                          ->orWhere('away_team_id', $teamId);
                    })->count();
                    $matchesPerTeam[$teamId] = $count;
                }

                // El nuevo equipo tiene 0 partidos
                $matchesPerTeam[$newTeamId] = 0;

                // Máximo de partidos que alguien ha jugado
                $maxMatches = max($matchesPerTeam);

                // Partidos que necesita el nuevo equipo (contra todos los existentes)
                $newTeamMatches = [];
                foreach ($existingTeamIds as $opponentId) {
                    $newTeamMatches[] = [
                        'home' => $newTeamId,
                        'away' => $opponentId
                    ];
                }

                // Configuración de fechas
                $startDate = Carbon::parse($validated['start_date'] ?? now()->addDays(7));
                $matchTime = $validated['match_time'] ?? '15:00';
                $daysBetween = $validated['days_between_matchdays'] ?? 7;

                // Obtener último número de jornada
                $lastMatchday = Matchday::where('tournament_id', $tournament->id)
                    ->orderBy('number', 'desc')
                    ->first();
                $matchdayNumber = $lastMatchday ? $lastMatchday->number + 1 : 1;

                // Equipos que necesitan partidos adicionales para equilibrar
                $teamsNeedingMatches = [];
                foreach ($existingTeamIds as $teamId) {
                    $deficit = $maxMatches - $matchesPerTeam[$teamId];
                    if ($deficit > 0) {
                        $teamsNeedingMatches[$teamId] = $deficit;
                    }
                }

                // Generar jornadas de integración
                // Cada jornada: nuevo equipo juega + posible partido extra para equilibrar
                $opponentIndex = 0;
                $currentDate = $startDate->copy();

                while ($opponentIndex < count($newTeamMatches)) {
                    $matchday = Matchday::create([
                        'tournament_id' => $tournament->id,
                        'number' => $matchdayNumber,
                        'name' => "Jornada {$matchdayNumber}",
                        'date' => $currentDate->format('Y-m-d')
                    ]);
                    $matchdaysCreated++;

                    $currentTime = Carbon::parse($matchTime);

                    // Partido del nuevo equipo
                    $match = $newTeamMatches[$opponentIndex];
                    Matchs::create([
                        'matchday_id' => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date' => $currentDate->copy()->setTimeFrom($currentTime),
                        'home_score' => 0,
                        'away_score' => 0,
                        'status' => 'Programado'
                    ]);
                    $matchesCreated++;
                    $currentTime->addMinutes(90);

                    // El oponente ya jugó en esta jornada, no puede jugar doble
                    $opponentPlaying = $match['away'];

                    // Buscar equipos que necesitan partidos extra y pueden jugar
                    // (no son el nuevo equipo ni el oponente actual)
                    $availableForExtra = [];
                    foreach ($teamsNeedingMatches as $teamId => $deficit) {
                        if ($teamId !== $opponentPlaying && $deficit > 0) {
                            $availableForExtra[] = $teamId;
                        }
                    }

                    // Si hay al menos 2 equipos disponibles, hacer partido extra
                    if (count($availableForExtra) >= 2) {
                        $team1 = $availableForExtra[0];
                        $team2 = $availableForExtra[1];

                        // Verificar que no hayan jugado ya entre ellos en esta ronda de integración
                        Matchs::create([
                            'matchday_id' => $matchday->id,
                            'home_team_id' => $team1,
                            'away_team_id' => $team2,
                            'match_date' => $currentDate->copy()->setTimeFrom($currentTime),
                            'home_score' => 0,
                            'away_score' => 0,
                            'status' => 'Programado'
                        ]);
                        $matchesCreated++;

                        // Reducir déficit
                        $teamsNeedingMatches[$team1]--;
                        $teamsNeedingMatches[$team2]--;

                        if ($teamsNeedingMatches[$team1] <= 0) {
                            unset($teamsNeedingMatches[$team1]);
                        }
                        if ($teamsNeedingMatches[$team2] <= 0) {
                            unset($teamsNeedingMatches[$team2]);
                        }
                    }

                    $opponentIndex++;
                    $matchdayNumber++;
                    $currentDate->addDays($daysBetween);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Equipo integrado exitosamente al torneo',
                'matches_created' => $matchesCreated,
                'matchdays_created' => $matchdaysCreated
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al agregar el equipo',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function cloneTournament(Request $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'copy_teams' => 'boolean',
            'copy_fixture' => 'boolean',
            'days_offset' => 'nullable|integer'
        ]);

        try {
            DB::beginTransaction();

            $newTournament = Tournament::create([
                'name' => $validated['name'],
                'description' => $tournament->description,
                'type' => $tournament->type,
                'start_date' => $validated['start_date'],
                'status' => 'Planificado'
            ]);

            if ($validated['copy_teams'] ?? true) {
                $teams = DB::table('tournament_team')
                    ->where('tournament_id', $tournament->id)
                    ->get();

                foreach ($teams as $team) {
                    DB::table('tournament_team')->insert([
                        'tournament_id' => $newTournament->id,
                        'team_id' => $team->team_id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            if ($validated['copy_fixture'] ?? false) {
                $tournament->load('matchdays.matches');
                $daysOffset = $validated['days_offset'] ?? 0;

                foreach ($tournament->matchdays as $oldMatchday) {
                    $newMatchday = Matchday::create([
                        'tournament_id' => $newTournament->id,
                        'number' => $oldMatchday->number,
                        'name' => $oldMatchday->name,
                        'date' => Carbon::parse($oldMatchday->date)->addDays($daysOffset)
                    ]);

                    foreach ($oldMatchday->matches as $oldMatch) {
                        Matchs::create([
                            'matchday_id' => $newMatchday->id,
                            'home_team_id' => $oldMatch->home_team_id,
                            'away_team_id' => $oldMatch->away_team_id,
                            'match_date' => Carbon::parse($oldMatch->match_date)->addDays($daysOffset),
                            'location' => $oldMatch->location,
                            'home_score' => 0,
                            'away_score' => 0,
                            'status' => 'Programado'
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Torneo clonado exitosamente',
                'tournament' => $newTournament->load('matchdays')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al clonar el torneo',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
