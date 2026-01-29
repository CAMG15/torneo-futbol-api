<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatchEventRequest;
use App\Models\Matchs;
use App\Models\Matchday;
use App\Models\MatchEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $matches = $query->orderBy('match_date', 'desc')->paginate(20);

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
        ]);

        $match = $matchday->matches()->create([
            'home_team_id' => $validated['home_team_id'],
            'away_team_id' => $validated['away_team_id'],
            'match_date' => $validated['match_date'],
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

    public function update(Request $request, Matchs $match): JsonResponse
    {
        $validated = $request->validate([
            'home_team_id' => 'sometimes|exists:teams,id',
            'away_team_id' => 'sometimes|exists:teams,id',
            'match_date' => 'sometimes|date',
            'location' => 'nullable|string|max:255',
            'home_score' => 'sometimes|integer|min:0',
            'away_score' => 'sometimes|integer|min:0',
            'status' => 'sometimes|in:Programado,En Vivo,Finalizado,Suspendido,Cancelado',
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
            'status' => 'required|in:Programado,En Vivo,Finalizado,Suspendido,Cancelado',
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
        if ($match->status !== 'Programado') {
            return response()->json([
                'error' => 'Solo se pueden iniciar partidos con estado Programado'
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
