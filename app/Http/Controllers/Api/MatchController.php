<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatchEventRequest;
use App\Models\Matchs;
use App\Models\Matchday;
use App\Models\MatchEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Matchs::with(['homeTeam', 'awayTeam', 'matchday.tournament']);

        // Filtro por estado
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Filtro por torneo
        if ($request->has('tournament_id') && $request->tournament_id !== null) {
            $query->whereHas('matchday', function ($q) use ($request) {
                $q->where('tournament_id', $request->tournament_id);
            });
        }

        // Filtro por jornada
        if ($request->has('matchday_id') && $request->matchday_id !== null) {
            $query->where('matchday_id', $request->matchday_id);
        }

        // Ordenar: En Vivo primero, luego Programados (asc), luego finalizados/resto (desc)
        $matches = $query->orderByRaw("
            CASE status
                WHEN 'En Vivo'    THEN 0
                WHEN 'Programado' THEN 1
                WHEN 'Pospuesto'  THEN 2
                ELSE 3
            END ASC
        ")->orderByRaw("
            CASE WHEN status IN ('En Vivo','Programado','Pospuesto')
                THEN match_date END ASC
        ")->orderByRaw("
            CASE WHEN status NOT IN ('En Vivo','Programado','Pospuesto')
                THEN match_date END DESC
        ")->paginate(500);

        return response()->json($matches);
    }

    public function show(Matchs $match): JsonResponse
    {
        return response()->json(
            $match->load(['homeTeam.currentPlayers', 'awayTeam.currentPlayers', 'events.player', 'matchday.tournament'])
        );
    }

    public function store(Request $request, Matchday $matchday): JsonResponse
    {
        $validated = $request->validate([
            'home_team_id' => 'required|exists:teams,id',
            'away_team_id' => 'required|exists:teams,id|different:home_team_id',
            'match_date' => 'required|date',
            'location' => 'nullable|string|max:255',
            'skip_validation' => 'nullable|boolean', // Permitir saltar validación de enfrentamiento
            'skip_time_validation' => 'nullable|boolean', // Permitir saltar validación de horario
        ]);

        $tournamentId = $matchday->tournament_id;
        $homeTeamId = $validated['home_team_id'];
        $awayTeamId = $validated['away_team_id'];
        $matchDate = $validated['match_date'];

        // Verificar si ya se enfrentaron en el torneo (a menos que se salte validación)
        if (!($validated['skip_validation'] ?? false)) {
            $existingMatches = $this->getMatchesBetweenTeams($tournamentId, $homeTeamId, $awayTeamId);

            if ($existingMatches->count() > 0) {
                return response()->json([
                    'warning' => 'matchup_conflict',
                    'warning_type' => 'matchup',
                    'existing_matches' => $existingMatches->map(function ($m) {
                        return [
                            'id' => $m->id,
                            'matchday' => $m->matchday->name,
                            'date' => $m->match_date,
                            'score' => $m->home_score . '-' . $m->away_score,
                            'status' => $m->status
                        ];
                    }),
                    'message' => 'Estos equipos ya se enfrentaron en este torneo'
                ], 409);
            }
        }

        // Verificar conflictos de horario (a menos que se salte validación)
        if (!($validated['skip_time_validation'] ?? false)) {
            $timeConflicts = $this->getMatchesAtSameTime($tournamentId, $matchDate);

            if ($timeConflicts->count() > 0) {
                return response()->json([
                    'warning' => 'time_conflict',
                    'warning_type' => 'time',
                    'conflicting_matches' => $timeConflicts->map(function ($m) {
                        return [
                            'id' => $m->id,
                            'matchday' => $m->matchday->name,
                            'date' => $m->match_date,
                            'home_team' => $m->homeTeam->name,
                            'away_team' => $m->awayTeam->name,
                            'status' => $m->status
                        ];
                    }),
                    'message' => 'Ya existe un partido programado a esta hora'
                ], 409);
            }
        }

        $match = $matchday->matches()->create([
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
            'match_date' => $matchDate,
            'location' => $validated['location'] ?? null,
            'home_score' => 0,
            'away_score' => 0,
            'status' => 'Programado'
        ]);

        return response()->json([
            'message' => 'Partido creado exitosamente',
            'match' => $match->load(['homeTeam', 'awayTeam'])
        ], 201);
    }

    /**
     * Verificar si dos equipos ya se enfrentaron en un torneo
     */
    public function checkMatchup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'home_team_id' => 'required|exists:teams,id',
            'away_team_id' => 'required|exists:teams,id|different:home_team_id',
        ]);

        $existingMatches = $this->getMatchesBetweenTeams(
            $validated['tournament_id'],
            $validated['home_team_id'],
            $validated['away_team_id']
        );

        return response()->json([
            'already_played' => $existingMatches->count() > 0,
            'match_count' => $existingMatches->count(),
            'matches' => $existingMatches->map(function ($m) {
                return [
                    'id' => $m->id,
                    'matchday' => $m->matchday->name,
                    'date' => $m->match_date,
                    'home_team' => $m->homeTeam->name,
                    'away_team' => $m->awayTeam->name,
                    'score' => $m->home_score . '-' . $m->away_score,
                    'status' => $m->status
                ];
            })
        ]);
    }

    /**
     * Obtener partidos entre dos equipos en un torneo
     */
    private function getMatchesBetweenTeams(int $tournamentId, int $team1Id, int $team2Id)
    {
        return Matchs::with(['matchday', 'homeTeam', 'awayTeam'])
            ->whereHas('matchday', function ($q) use ($tournamentId) {
                $q->where('tournament_id', $tournamentId);
            })
            ->where(function ($q) use ($team1Id, $team2Id) {
                $q->where(function ($q2) use ($team1Id, $team2Id) {
                    $q2->where('home_team_id', $team1Id)
                       ->where('away_team_id', $team2Id);
                })->orWhere(function ($q2) use ($team1Id, $team2Id) {
                    $q2->where('home_team_id', $team2Id)
                       ->where('away_team_id', $team1Id);
                });
            })
            ->get();
    }

    /**
     * Obtener partidos en el mismo horario en un torneo
     * Considera un margen de 90 minutos (duración típica de un partido)
     */
    private function getMatchesAtSameTime(int $tournamentId, string $matchDate)
    {
        $dateTime = new \DateTime($matchDate);
        $startWindow = (clone $dateTime)->modify('-89 minutes');
        $endWindow = (clone $dateTime)->modify('+89 minutes');

        return Matchs::with(['matchday', 'homeTeam', 'awayTeam'])
            ->whereHas('matchday', function ($q) use ($tournamentId) {
                $q->where('tournament_id', $tournamentId);
            })
            ->whereNotIn('status', ['Cancelado', 'Finalizado'])
            ->whereBetween('match_date', [$startWindow->format('Y-m-d H:i:s'), $endWindow->format('Y-m-d H:i:s')])
            ->get();
    }

    /**
     * Verificar disponibilidad de horario
     */
    public function checkTimeAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'match_date' => 'required|date',
        ]);

        $conflicts = $this->getMatchesAtSameTime(
            $validated['tournament_id'],
            $validated['match_date']
        );

        return response()->json([
            'is_available' => $conflicts->count() === 0,
            'conflict_count' => $conflicts->count(),
            'conflicting_matches' => $conflicts->map(function ($m) {
                return [
                    'id' => $m->id,
                    'matchday' => $m->matchday->name,
                    'date' => $m->match_date,
                    'home_team' => $m->homeTeam->name,
                    'away_team' => $m->awayTeam->name,
                    'status' => $m->status
                ];
            })
        ]);
    }

    /**
     * Posponer un partido (cambiar fecha)
     */
    public function postponeMatch(Request $request, Matchs $match): JsonResponse
    {
        $validated = $request->validate([
            'new_date' => 'required|date|after:now',
            'reason' => 'nullable|string|max:500',
        ]);

        $oldDate = $match->match_date;

        $match->update([
            'match_date' => $validated['new_date'],
            'status' => 'Pospuesto'
        ]);

        return response()->json([
            'message' => 'Partido pospuesto exitosamente',
            'match' => $match->fresh()->load(['homeTeam', 'awayTeam']),
            'old_date' => $oldDate,
            'new_date' => $validated['new_date']
        ]);
    }

    /**
     * Cancelar un partido
     */
    public function cancelMatch(Request $request, Matchs $match): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if ($match->status === 'Finalizado') {
            return response()->json([
                'error' => 'No se puede cancelar un partido finalizado'
            ], 400);
        }

        $match->update(['status' => 'Cancelado']);

        return response()->json([
            'message' => 'Partido cancelado exitosamente',
            'match' => $match->fresh()->load(['homeTeam', 'awayTeam'])
        ]);
    }

    public function reorderMatches(Request $request, Matchday $matchday): JsonResponse
    {
        $validated = $request->validate([
            'match_ids'   => 'required|array|min:1',
            'match_ids.*' => 'integer|exists:matches,id',
        ]);

        $orderedMatchIds = $validated['match_ids'];

        $matches = Matchs::whereIn('id', $orderedMatchIds)
            ->where('matchday_id', $matchday->id)
            ->orderBy('match_date')
            ->get();

        if ($matches->count() !== count($orderedMatchIds)) {
            return response()->json(['error' => 'Algunos partidos no pertenecen a esta jornada'], 422);
        }

        if ($matches->whereIn('status', ['Finalizado', 'En Vivo'])->isNotEmpty()) {
            return response()->json(['error' => 'No se pueden reordenar partidos en curso o finalizados'], 422);
        }

        $sortedDates = $matches->sortBy('match_date')->pluck('match_date')->values();
        $matchById   = $matches->keyBy('id');

        DB::transaction(function () use ($orderedMatchIds, $sortedDates, $matchById) {
            foreach ($orderedMatchIds as $pos => $matchId) {
                $matchById[$matchId]->update(['match_date' => $sortedDates[$pos]]);
            }
        });

        return response()->json([
            'message' => 'Orden actualizado exitosamente',
            'matches' => Matchs::whereIn('id', $orderedMatchIds)
                ->with(['homeTeam', 'awayTeam'])
                ->orderBy('match_date')
                ->get(),
        ]);
    }

    public function update(Request $request, Matchs $match): JsonResponse
    {
        $validated = $request->validate([
            'home_team_id' => 'sometimes|exists:teams,id',
            'away_team_id' => 'sometimes|exists:teams,id',
            'match_date' => 'sometimes|date',
            'location' => 'nullable|string|max:255',
            'home_score' => 'sometimes|integer|min:0',
            'away_score' => 'sometimes|integer|min:0',
            'status' => 'sometimes|in:Programado,En Vivo,Finalizado,Suspendido,Cancelado,Pospuesto',
        ]);

        if (isset($validated['home_team_id']) && isset($validated['away_team_id'])) {
            if ($validated['home_team_id'] === $validated['away_team_id']) {
                return response()->json([
                    'error' => 'El equipo local y visitante no pueden ser el mismo'
                ], 400);
            }
        }

        $match->update($validated);

        return response()->json([
            'message' => 'Partido actualizado exitosamente',
            'match' => $match->fresh()->load(['homeTeam', 'awayTeam'])
        ]);
    }

    public function destroy(Matchs $match): JsonResponse
    {
        $match->events()->delete();
        $match->delete();

        return response()->json([
            'message' => 'Partido eliminado exitosamente'
        ]);
    }

    public function updateStatus(Request $request, Matchs $match): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:Programado,En Vivo,Finalizado,Suspendido,Cancelado,Pospuesto',
        ]);

        $match->update(['status' => $validated['status']]);

        if ($validated['status'] === 'Finalizado') {
            $this->updateTeamStats($match);
        }

        return response()->json([
            'message' => 'Estado del partido actualizado',
            'match' => $match->fresh()
        ]);
    }

    public function addEvent(StoreMatchEventRequest $request, Matchs $match): JsonResponse
    {
        $validated = $request->validated();

        // Verificar que el equipo participa en el partido
        if ((int) $validated['team_id'] !== (int) $match->home_team_id && (int) $validated['team_id'] !== (int) $match->away_team_id) {
            return response()->json([
                'error' => 'El equipo no participa en este partido'
            ], 400);
        }

        $event = $match->events()->create($validated);

        $player = $event->player;
        switch ($event->event_type) {
            case 'Gol':
                $player->increment('goals');
                if ((int) $event->team_id === (int) $match->home_team_id) {
                    $match->update(['home_score' => ($match->home_score ?? 0) + 1]);
                } else {
                    $match->update(['away_score' => ($match->away_score ?? 0) + 1]);
                }
                break;
            case 'Autogol':
                if ((int) $event->team_id === (int) $match->home_team_id) {
                    $match->update(['away_score' => ($match->away_score ?? 0) + 1]);
                } else {
                    $match->update(['home_score' => ($match->home_score ?? 0) + 1]);
                }
                break;
            case 'Asistencia':
                $player->increment('assists');
                break;
            case 'Tarjeta Amarilla':
                $player->increment('yellow_cards');
                break;
            case 'Tarjeta Roja':
                $player->increment('red_cards');
                break;
        }

        return response()->json([
            'message' => 'Evento registrado exitosamente',
            'event' => $event->load('player'),
            'match' => $match->fresh()
        ], 201);
    }

    public function removeEvent(Matchs $match, MatchEvent $event): JsonResponse
    {
        if ($event->match_id !== $match->id) {
            return response()->json([
                'error' => 'El evento no pertenece a este partido'
            ], 400);
        }

        $player = $event->player;
        switch ($event->event_type) {
            case 'Gol':
                $player->decrement('goals');
                if ((int) $event->team_id === (int) $match->home_team_id) {
                    $match->update(['home_score' => max(0, ($match->home_score ?? 0) - 1)]);
                } else {
                    $match->update(['away_score' => max(0, ($match->away_score ?? 0) - 1)]);
                }
                break;
            case 'Autogol':
                if ((int) $event->team_id === (int) $match->home_team_id) {
                    $match->update(['away_score' => max(0, ($match->away_score ?? 0) - 1)]);
                } else {
                    $match->update(['home_score' => max(0, ($match->home_score ?? 0) - 1)]);
                }
                break;
            case 'Asistencia':
                $player->decrement('assists');
                break;
            case 'Tarjeta Amarilla':
                $player->decrement('yellow_cards');
                break;
            case 'Tarjeta Roja':
                $player->decrement('red_cards');
                break;
        }

        $event->delete();

        return response()->json([
            'message' => 'Evento eliminado exitosamente',
            'match' => $match->fresh()
        ]);
    }

    public function startMatch(Matchs $match): JsonResponse
    {
        if (!in_array($match->status, ['Programado', 'Pospuesto', 'Suspendido'])) {
            return response()->json([
                'error' => 'Solo se pueden iniciar partidos con estado Programado, Pospuesto o Suspendido'
            ], 400);
        }

        $match->update(['status' => 'En Vivo']);

        return response()->json(
            $match->fresh()->load(['homeTeam.currentPlayers', 'awayTeam.currentPlayers', 'events.player', 'matchday.tournament'])
        );
    }

    public function finishMatch(Matchs $match): JsonResponse
    {
        if ($match->status !== 'En Vivo') {
            return response()->json([
                'error' => 'Solo se pueden finalizar partidos con estado En Vivo'
            ], 400);
        }

        $match->update(['status' => 'Finalizado']);
        $this->updateTeamStats($match);

        return response()->json(
            $match->fresh()->load(['homeTeam.currentPlayers', 'awayTeam.currentPlayers', 'events.player', 'matchday.tournament'])
        );
    }

    public function upcoming(): JsonResponse
    {
        $matches = Matchs::with(['homeTeam', 'awayTeam', 'matchday.tournament'])
            ->where('status', 'Programado')
            ->where('match_date', '>', now())
            ->orderBy('match_date')
            ->paginate(20);

        return response()->json($matches);
    }

    public function live(): JsonResponse
    {
        $matches = Matchs::with(['homeTeam', 'awayTeam', 'events.player', 'matchday.tournament'])
            ->where('status', 'En Vivo')
            ->get();

        return response()->json($matches);
    }

    private function updateTeamStats(Matchs $match): void
    {
        $homeTeam = $match->homeTeam;
        $awayTeam = $match->awayTeam;

        $homeTeam->increment('matches_played');
        $awayTeam->increment('matches_played');
        $homeTeam->increment('goals_for', $match->home_score);
        $homeTeam->increment('goals_against', $match->away_score);
        $awayTeam->increment('goals_for', $match->away_score);
        $awayTeam->increment('goals_against', $match->home_score);

        if ($match->home_score > $match->away_score) {
            $homeTeam->increment('wins');
            $homeTeam->increment('points', 3);
            $awayTeam->increment('losses');
        } elseif ($match->home_score < $match->away_score) {
            $awayTeam->increment('wins');
            $awayTeam->increment('points', 3);
            $homeTeam->increment('losses');
        } else {
            $homeTeam->increment('draws');
            $awayTeam->increment('draws');
            $homeTeam->increment('points', 1);
            $awayTeam->increment('points', 1);
        }
    }
}
