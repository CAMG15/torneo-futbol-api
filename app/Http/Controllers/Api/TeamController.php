<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamRequest;
use App\Models\Team;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
        $team->load(['captain', 'currentPlayers']);

        // Stats por torneo calculadas dinámicamente desde los partidos
        $tournamentIds = DB::table('tournament_team')
            ->where('team_id', $team->id)
            ->pluck('tournament_id');

        $tournamentStats = [];
        foreach ($tournamentIds as $tid) {
            $tournament = DB::table('tournaments')->where('id', $tid)->first(['id', 'name', 'status']);
            if (!$tournament) continue;

            $homeMatches = DB::table('matches')
                ->join('matchdays', 'matches.matchday_id', '=', 'matchdays.id')
                ->where('matchdays.tournament_id', $tid)
                ->where('matches.home_team_id', $team->id)
                ->where('matches.status', 'Finalizado')
                ->get(['matches.home_score', 'matches.away_score']);

            $awayMatches = DB::table('matches')
                ->join('matchdays', 'matches.matchday_id', '=', 'matchdays.id')
                ->where('matchdays.tournament_id', $tid)
                ->where('matches.away_team_id', $team->id)
                ->where('matches.status', 'Finalizado')
                ->get(['matches.home_score', 'matches.away_score']);

            $wins = 0; $draws = 0; $losses = 0; $gf = 0; $ga = 0;
            foreach ($homeMatches as $m) {
                $gf += $m->home_score ?? 0; $ga += $m->away_score ?? 0;
                if ($m->home_score > $m->away_score)      $wins++;
                elseif ($m->home_score == $m->away_score) $draws++;
                else                                       $losses++;
            }
            foreach ($awayMatches as $m) {
                $gf += $m->away_score ?? 0; $ga += $m->home_score ?? 0;
                if ($m->away_score > $m->home_score)      $wins++;
                elseif ($m->home_score == $m->away_score) $draws++;
                else                                       $losses++;
            }
            $played = $homeMatches->count() + $awayMatches->count();
            if ($played === 0 && $tournament->status === 'Planificado') continue;

            $tournamentStats[] = [
                'tournament_id'   => $tournament->id,
                'tournament_name' => $tournament->name,
                'tournament_status' => $tournament->status,
                'matches_played'  => $played,
                'wins'            => $wins,
                'draws'           => $draws,
                'losses'          => $losses,
                'goals_for'       => $gf,
                'goals_against'   => $ga,
                'goal_difference' => $gf - $ga,
                'points'          => ($wins * 3) + $draws,
            ];
        }

        $data = $team->toArray();
        $data['tournament_stats'] = $tournamentStats;

        return response()->json($data);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('teams', 'name')->ignore($team->id)->where('tenant_id', $request->user()?->tenant_id),
            ],
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

        // Verificar que el jugador no esté en otro equipo del mismo torneo
        $tournamentIds = DB::table('tournament_team')
            ->where('team_id', $team->id)
            ->pluck('tournament_id');

        if ($tournamentIds->isNotEmpty()) {
            $conflict = DB::table('player_team')
                ->join('tournament_team', 'player_team.team_id', '=', 'tournament_team.team_id')
                ->join('tournaments', 'tournament_team.tournament_id', '=', 'tournaments.id')
                ->join('teams', 'player_team.team_id', '=', 'teams.id')
                ->where('player_team.player_id', $validated['player_id'])
                ->whereNull('player_team.left_at')
                ->whereIn('tournament_team.tournament_id', $tournamentIds)
                ->where('player_team.team_id', '!=', $team->id)
                ->select('teams.name as team_name', 'tournaments.name as tournament_name')
                ->first();

            if ($conflict) {
                return response()->json([
                    'error' => "El jugador ya pertenece al equipo \"{$conflict->team_name}\" en el torneo \"{$conflict->tournament_name}\"."
                ], 400);
            }
        }

        if (!empty($validated['jersey_number'])) {
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
