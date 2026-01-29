<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamRequest;
use App\Models\Team;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $teams = Team::with(['captain', 'currentPlayers'])
            ->where('active', true)
            ->orderBy('name')
            ->paginate(20);

        return response()->json($teams);
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('teams', 'public');
        }

        $team = Team::create($validated);

        return response()->json([
            'message' => 'Equipo creado exitosamente',
            'team' => $team
        ], 201);
    }

    public function show(Team $team): JsonResponse
    {
        return response()->json(
            $team->load(['captain', 'currentPlayers', 'homeMatches.awayTeam', 'awayMatches.homeTeam'])
        );
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:teams,name,' . $team->id,
            'short_name' => 'nullable|string|max:10',
            'logo' => 'nullable|image|max:2048',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'captain_id' => 'nullable|exists:players,id',
            'active' => 'sometimes|boolean',
        ]);

        if ($request->hasFile('logo')) {
            if ($team->logo) {
                Storage::disk('public')->delete($team->logo);
            }
            $validated['logo'] = $request->file('logo')->store('teams', 'public');
        }

        $team->update($validated);

        return response()->json([
            'message' => 'Equipo actualizado exitosamente',
            'team' => $team->fresh()
        ]);
    }

    public function destroy(Team $team): JsonResponse
    {
        $team->update(['active' => false]);

        return response()->json([
            'message' => 'Equipo desactivado exitosamente'
        ]);
    }

    public function addPlayer(Request $request, Team $team): JsonResponse
    {
        $validated = $request->validate([
            'player_id' => 'required|exists:players,id',
            'jersey_number' => 'nullable|integer|min:1|max:99',
            'joined_at' => 'required|date'
        ]);

        $exists = $team->players()
            ->wherePivotNull('left_at')
            ->where('player_id', $validated['player_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'El jugador ya está en el equipo'
            ], 400);
        }

        if ($validated['jersey_number']) {
            $jerseyTaken = $team->players()
                ->wherePivotNull('left_at')
                ->wherePivot('jersey_number', $validated['jersey_number'])
                ->exists();

            if ($jerseyTaken) {
                return response()->json([
                    'error' => 'El número de camiseta ya está en uso'
                ], 400);
            }
        }

        $team->players()->attach($validated['player_id'], [
            'jersey_number' => $validated['jersey_number'] ?? null,
            'joined_at' => $validated['joined_at']
        ]);

        return response()->json([
            'message' => 'Jugador agregado al equipo exitosamente',
            'team' => $team->fresh()->load('currentPlayers')
        ]);
    }

    public function removePlayer(Team $team, Player $player): JsonResponse
    {
        $pivotRecord = $team->players()
            ->wherePivotNull('left_at')
            ->where('player_id', $player->id)
            ->first();

        if (!$pivotRecord) {
            return response()->json([
                'error' => 'El jugador no está en el equipo'
            ], 404);
        }

        $team->players()->updateExistingPivot($player->id, [
            'left_at' => now()
        ]);

        if ($team->captain_id === $player->id) {
            $team->update(['captain_id' => null]);
        }

        return response()->json([
            'message' => 'Jugador removido del equipo exitosamente'
        ]);
    }

    public function standings(): JsonResponse
    {
        $teams = Team::where('active', true)
            ->orderByDesc('points')
            ->orderByRaw('(goals_for - goals_against) DESC')
            ->orderByDesc('goals_for')
            ->get();

        return response()->json($teams);
    }
}
