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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TournamentController extends Controller
{
    public function index(): JsonResponse
    {
        $tournaments = Tournament::with('matchdays')
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
        return response()->json(
            $tournament->load(['teams', 'matchdays.matches.homeTeam', 'matchdays.matches.awayTeam'])
        );
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

    public function removeTeam(Tournament $tournament, Team $team): JsonResponse
    {
        $deleted = DB::table('tournament_team')
            ->where('tournament_id', $tournament->id)
            ->where('team_id', $team->id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'error' => 'El equipo no está en este torneo'
            ], 404);
        }

        return response()->json([
            'message' => 'Equipo removido del torneo exitosamente'
        ]);
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
     */
    private function distributeMatchesSingleDay(Tournament $tournament, array $matches, array $config, MatchdaySchedule $schedule): array
    {
        $slots = $schedule->slots()->orderBy('slot_order')->get();
        $matchesPerMatchday = $slots->sum('max_matches');

        $matchdays = array_chunk($matches, $matchesPerMatchday);
        $startDate = Carbon::parse($config['start_date']);
        $matchdayNumber = 1;

        foreach ($matchdays as $matchdayMatches) {
            $matchday = Matchday::create([
                'tournament_id' => $tournament->id,
                'number' => $matchdayNumber,
                'name' => "Jornada {$matchdayNumber}",
                'date' => $startDate->format('Y-m-d'),
                'matchday_schedule_id' => $schedule->id
            ]);

            $matchIndex = 0;
            foreach ($slots as $slot) {
                for ($m = 0; $m < $slot->max_matches && $matchIndex < count($matchdayMatches); $m++) {
                    $match = $matchdayMatches[$matchIndex];
                    Matchs::create([
                        'matchday_id' => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date' => $startDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location' => $slot->location ?? $config['location'] ?? null,
                        'home_score' => 0,
                        'away_score' => 0,
                        'status' => 'Programado'
                    ]);
                    $matchIndex++;
                }
            }

            $startDate->addDays($config['days_between_matchdays']);
            $matchdayNumber++;
        }

        return [
            'matchdays_created' => count($matchdays),
            'total_matches' => count($matches)
        ];
    }

    /**
     * Distribuir partidos en modo multi_day (varios días por jornada)
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

        $matchChunks = array_chunk($matches, $matchesPerWeek);
        $startDate = Carbon::parse($config['start_date']);

        // Encontrar el lunes de la semana de inicio
        $weekStart = $startDate->copy()->startOfWeek(Carbon::MONDAY);
        $matchdayNumber = 1;
        $matchdaysCreated = 0;

        foreach ($matchChunks as $weekMatches) {
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

            $matchIndex = 0;
            foreach ($slots as $slot) {
                $slotDate = $this->getDateForDayOfWeek($weekStart, $slot->day_of_week);

                for ($m = 0; $m < $slot->max_matches && $matchIndex < count($weekMatches); $m++) {
                    $match = $weekMatches[$matchIndex];
                    Matchs::create([
                        'matchday_id' => $matchday->id,
                        'home_team_id' => $match['home'],
                        'away_team_id' => $match['away'],
                        'match_date' => $slotDate->copy()->setTimeFromTimeString($slot->start_time),
                        'location' => $slot->location ?? $config['location'] ?? null,
                        'home_score' => 0,
                        'away_score' => 0,
                        'status' => 'Programado'
                    ]);
                    $matchIndex++;
                }
            }

            $weekStart->addDays($config['days_between_matchdays']);
            $matchdayNumber++;
            $matchdaysCreated++;
        }

        return [
            'matchdays_created' => $matchdaysCreated,
            'total_matches' => count($matches)
        ];
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

            return $this->generateFixture($request, $tournament);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al regenerar el fixture',
                'message' => $e->getMessage()
            ], 500);
        }
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
