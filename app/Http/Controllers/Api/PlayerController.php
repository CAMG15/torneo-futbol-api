<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlayerRequest;
use App\Http\Requests\UpdatePlayerRequest;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PlayerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Player::with('currentTeams')
            ->where('active', true);

        // Filtro por posición
        if ($request->has('position') && $request->position !== '') {
            $query->where('position', $request->position);
        }

        // Filtro por equipo
        if ($request->has('team_id') && $request->team_id !== null) {
            $query->whereHas('currentTeams', function ($q) use ($request) {
                $q->where('teams.id', $request->team_id);
            });
        }

        $players = $query->orderBy('lastname')->paginate(20);

        return response()->json($players);
    }

    public function store(StorePlayerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('photo')) {
            $validated['photo'] = $request->file('photo')->store('players', 'public');
        }

        $player = Player::create($validated);

        return response()->json([
            'message' => 'Jugador creado exitosamente',
            'player' => $player
        ], 201);
    }

    public function show(Player $player): JsonResponse
    {
        return response()->json(
            $player->load(['currentTeams', 'matchEvents'])
        );
    }

    public function update(UpdatePlayerRequest $request, Player $player): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('photo')) {
            if ($player->photo) {
                Storage::disk('public')->delete($player->photo);
            }
            $validated['photo'] = $request->file('photo')->store('players', 'public');
        }

        $player->update($validated);

        return response()->json([
            'message' => 'Jugador actualizado exitosamente',
            'player' => $player->fresh()
        ]);
    }

    public function destroy(Player $player): JsonResponse
    {
        $player->update(['active' => false]);

        return response()->json([
            'message' => 'Jugador desactivado exitosamente'
        ]);
    }

    public function statistics(): JsonResponse
    {
        return response()->json([
            'top_scorers' => Player::where('active', true)
                ->orderBy('goals', 'desc')
                ->take(10)
                ->get(['id', 'name', 'lastname', 'goals']),
            'top_assisters' => Player::where('active', true)
                ->orderBy('assists', 'desc')
                ->take(10)
                ->get(['id', 'name', 'lastname', 'assists']),
            'most_yellow_cards' => Player::where('active', true)
                ->orderBy('yellow_cards', 'desc')
                ->take(10)
                ->get(['id', 'name', 'lastname', 'yellow_cards']),
            'most_red_cards' => Player::where('active', true)
                ->orderBy('red_cards', 'desc')
                ->take(10)
                ->get(['id', 'name', 'lastname', 'red_cards']),
        ]);
    }
}
