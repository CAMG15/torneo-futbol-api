<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduleConstraint;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ScheduleConstraintController extends Controller
{
    /**
     * Agregar restricción de horario
     */
    public function addConstraint(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'team_id' => 'nullable|exists:teams,id',
            'constraint_type' => 'required|in:DiaNoDisponible,HorarioNoDisponible,UbicacionRequerida,DescansoMinimo,MaxPartidosPorDia',
            'constraint_data' => 'required|array'
        ]);

        $constraint = ScheduleConstraint::create([
            'tournament_id' => $tournament->id,
            'team_id' => $validated['team_id'] ?? null,
            'constraint_type' => $validated['constraint_type'],
            'constraint_data' => $validated['constraint_data']
        ]);

        return response()->json($constraint, 201);
    }

    /**
     * Obtener todas las restricciones
     */
    public function getConstraints(Tournament $tournament)
    {
        $constraints = ScheduleConstraint::where('tournament_id', $tournament->id)
            ->where('active', true)
            ->with('team')
            ->get();

        return response()->json($constraints);
    }

    /**
     * Agregar fecha bloqueada (feriados, eventos, etc.)
     */
    public function addBlackoutDate(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'blackout_date' => 'required|date',
            'reason' => 'nullable|string'
        ]);

        DB::table('blackout_dates')->insert([
            'tournament_id' => $tournament->id,
            'blackout_date' => $validated['blackout_date'],
            'reason' => $validated['reason'] ?? null,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json(['message' => 'Fecha bloqueada agregada']);
    }

    /**
     * Validar si una fecha/hora es válida según restricciones
     */
    public function validateSchedule(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:teams,id',
            'match_date' => 'required|date'
        ]);

        $matchDate = Carbon::parse($validated['match_date']);
        $violations = [];

        // Verificar fechas bloqueadas
        $blackoutDates = DB::table('blackout_dates')
            ->where('tournament_id', $tournament->id)
            ->whereDate('blackout_date', $matchDate->format('Y-m-d'))
            ->get();

        if ($blackoutDates->count() > 0) {
            foreach ($blackoutDates as $blackout) {
                $violations[] = [
                    'type' => 'FechaBloqueada',
                    'message' => 'Fecha no disponible: ' . ($blackout->reason ?? 'Sin especificar')
                ];
            }
        }

        // Verificar restricciones del equipo
        $constraints = ScheduleConstraint::where('tournament_id', $tournament->id)
            ->where(function($q) use ($validated) {
                $q->whereNull('team_id')
                  ->orWhere('team_id', $validated['team_id']);
            })
            ->where('active', true)
            ->get();

        foreach ($constraints as $constraint) {
            $violation = $this->checkConstraintViolation($constraint, $matchDate);
            if ($violation) {
                $violations[] = $violation;
            }
        }

        // Verificar descanso mínimo
        $recentMatches = DB::table('matches')
            ->join('matchdays', 'matches.matchday_id', '=', 'matchdays.id')
            ->where('matchdays.tournament_id', $tournament->id)
            ->where(function($q) use ($validated) {
                $q->where('home_team_id', $validated['team_id'])
                  ->orWhere('away_team_id', $validated['team_id']);
            })
            ->where('matches.status', '!=', 'Suspendido')
            ->orderBy('match_date', 'desc')
            ->first();

        if ($recentMatches) {
            $lastMatchDate = Carbon::parse($recentMatches->match_date);
            $daysDiff = $lastMatchDate->diffInDays($matchDate);
            
            // Descanso mínimo de 2 días
            if ($daysDiff < 2) {
                $violations[] = [
                    'type' => 'DescansoInsuficiente',
                    'message' => "El equipo necesita al menos 2 días de descanso. Último partido: {$lastMatchDate->format('d/m/Y')}"
                ];
            }
        }

        return response()->json([
            'valid' => count($violations) === 0,
            'violations' => $violations,
            'match_date' => $matchDate->format('Y-m-d H:i')
        ]);
    }

    private function checkConstraintViolation($constraint, $matchDate)
    {
        $data = $constraint->constraint_data;

        switch ($constraint->constraint_type) {
            case 'DiaNoDisponible':
                // Ejemplo: ['days' => ['Sunday', 'Monday']]
                if (in_array($matchDate->format('l'), $data['days'] ?? [])) {
                    return [
                        'type' => 'DiaNoDisponible',
                        'message' => 'El equipo no está disponible los ' . implode(', ', $data['days'])
                    ];
                }
                break;

            case 'HorarioNoDisponible':
                // Ejemplo: ['start' => '08:00', 'end' => '14:00']
                $matchTime = $matchDate->format('H:i');
                if (isset($data['start']) && isset($data['end'])) {
                    if ($matchTime >= $data['start'] && $matchTime <= $data['end']) {
                        return [
                            'type' => 'HorarioNoDisponible',
                            'message' => "Horario no disponible: {$data['start']} - {$data['end']}"
                        ];
                    }
                }
                break;

            case 'UbicacionRequerida':
                // Se valida al asignar ubicación al partido
                break;

            case 'MaxPartidosPorDia':
                // Se valida contando partidos del día
                break;
        }

        return null;
    }

    /**
     * Generar sugerencias de fechas válidas
     */
    public function suggestAvailableDates(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:teams,id',
            'start_date' => 'required|date',
            'num_suggestions' => 'integer|min:1|max:10'
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $suggestions = [];
        $numSuggestions = $validated['num_suggestions'] ?? 5;
        $attempts = 0;
        $maxAttempts = 30;

        while (count($suggestions) < $numSuggestions && $attempts < $maxAttempts) {
            $response = $this->validateSchedule(
                new Request([
                    'team_id' => $validated['team_id'],
                    'match_date' => $startDate->format('Y-m-d H:i:s')
                ]),
                $tournament
            );

            $responseData = json_decode($response->getContent(), true);

            if ($responseData['valid']) {
                $suggestions[] = [
                    'date' => $startDate->format('Y-m-d'),
                    'day_name' => $startDate->locale('es')->dayName,
                    'formatted_date' => $startDate->format('d/m/Y')
                ];
            }

            $startDate->addDay();
            $attempts++;
        }

        return response()->json([
            'suggestions' => $suggestions,
            'team_id' => $validated['team_id']
        ]);
    }

    /**
     * Configuración rápida de disponibilidad
     */
    public function quickAvailabilitySetup(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'team_id' => 'nullable|exists:teams,id',
            'available_days' => 'required|array',
            'available_days.*' => 'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'available_hours_start' => 'required|date_format:H:i',
            'available_hours_end' => 'required|date_format:H:i',
            'min_rest_days' => 'integer|min:1|max:7'
        ]);

        try {
            DB::beginTransaction();

            // Días NO disponibles (inverso de los disponibles)
            $allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $unavailableDays = array_diff($allDays, $validated['available_days']);

            if (!empty($unavailableDays)) {
                ScheduleConstraint::create([
                    'tournament_id' => $tournament->id,
                    'team_id' => $validated['team_id'] ?? null,
                    'constraint_type' => 'DiaNoDisponible',
                    'constraint_data' => ['days' => array_values($unavailableDays)]
                ]);
            }

            // Horarios NO disponibles (antes y después del rango)
            ScheduleConstraint::create([
                'tournament_id' => $tournament->id,
                'team_id' => $validated['team_id'] ?? null,
                'constraint_type' => 'HorarioNoDisponible',
                'constraint_data' => [
                    'before' => $validated['available_hours_start'],
                    'after' => $validated['available_hours_end']
                ]
            ]);

            // Descanso mínimo
            if (isset($validated['min_rest_days'])) {
                ScheduleConstraint::create([
                    'tournament_id' => $tournament->id,
                    'team_id' => $validated['team_id'] ?? null,
                    'constraint_type' => 'DescansoMinimo',
                    'constraint_data' => ['days' => $validated['min_rest_days']]
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Disponibilidad configurada exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}