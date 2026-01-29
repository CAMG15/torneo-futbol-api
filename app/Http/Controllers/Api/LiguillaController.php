<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentPhase;
use App\Models\PlayoffBracket;
use App\Models\Matchday;
use App\Models\Matchs;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LiguillaController extends Controller
{
    /**
     * Obtener tabla de posiciones con indicador de clasificados
     */
    public function standings(Tournament $tournament, Request $request): JsonResponse
    {
        $teams = $tournament->teams()->get();
        $liguillaTeams = (int) $request->input('liguilla_teams', 8);

        // Calcular estadísticas por torneo
        $standings = $this->calculateStandings($tournament, $teams);

        // Marcar clasificados
        foreach ($standings as $index => &$team) {
            $position = $index + 1;
            $team['position'] = $position;
            $team['classified_liguilla'] = $position <= $liguillaTeams;
            $team['classified_copa'] = $position > $liguillaTeams;
        }

        return response()->json([
            'standings' => $standings,
            'liguilla_teams' => $liguillaTeams,
            'total_teams' => count($standings)
        ]);
    }

    /**
     * Generar liguilla (y copa opcionalmente)
     */
    public function generateLiguilla(Request $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validate([
            'liguilla_teams' => 'required|integer|in:4,8,16',
            'include_copa' => 'boolean',
            'copa_teams' => 'nullable|integer|in:4,8,16',
            'include_repechaje' => 'boolean',
            'format' => 'required|in:single_match,two_legs',
            'final_format' => 'required|in:single_match,two_legs',
            'tiebreaker' => 'required|in:global_score,away_goals,extra_time,higher_seed',
            'higher_seed_home_second' => 'boolean',
            'start_date' => 'required|date',
            'days_between_legs' => 'required|integer|min:1|max:14',
            'days_between_rounds' => 'required|integer|min:1|max:30',
            'match_times' => 'nullable|array',
            'match_times.cuartos' => 'nullable|date_format:H:i',
            'match_times.semifinal' => 'nullable|date_format:H:i',
            'match_times.final' => 'nullable|date_format:H:i',
        ]);

        // Verificar que no exista ya una liguilla
        if ($tournament->hasLiguilla()) {
            return response()->json([
                'error' => 'Este torneo ya tiene una liguilla generada. Elimínela primero.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $teams = $tournament->teams()->get();
            $standings = $this->calculateStandings($tournament, $teams);

            $liguillaTeams = $validated['liguilla_teams'];

            if (count($standings) < $liguillaTeams) {
                return response()->json([
                    'error' => "Se necesitan al menos {$liguillaTeams} equipos para la liguilla"
                ], 400);
            }

            $config = [
                'liguilla_teams' => $liguillaTeams,
                'include_copa' => $validated['include_copa'] ?? false,
                'copa_teams' => $validated['copa_teams'] ?? $liguillaTeams,
                'include_repechaje' => $validated['include_repechaje'] ?? false,
                'format' => $validated['format'],
                'final_format' => $validated['final_format'],
                'tiebreaker' => $validated['tiebreaker'],
                'higher_seed_home_second' => $validated['higher_seed_home_second'] ?? true,
                'days_between_legs' => $validated['days_between_legs'],
                'days_between_rounds' => $validated['days_between_rounds'],
                'match_times' => $validated['match_times'] ?? [
                    'cuartos' => '20:00',
                    'semifinal' => '20:00',
                    'final' => '18:00'
                ],
            ];

            // Crear fase de liguilla
            $liguillaPhase = TournamentPhase::create([
                'tournament_id' => $tournament->id,
                'phase_type' => 'liguilla',
                'name' => 'Liguilla',
                'phase_order' => 1,
                'status' => 'pending',
                'config' => $config
            ]);

            // Generar brackets de liguilla
            $liguillaStandings = array_slice($standings, 0, $liguillaTeams);
            $this->generateBrackets($liguillaPhase, $liguillaStandings, $config, Carbon::parse($validated['start_date']));

            // Generar copa si está habilitada
            $copaPhase = null;
            if ($config['include_copa']) {
                $copaTeams = $config['copa_teams'];
                $copaStandings = array_slice($standings, $liguillaTeams, $copaTeams);

                if (count($copaStandings) >= 2) {
                    $copaPhase = TournamentPhase::create([
                        'tournament_id' => $tournament->id,
                        'phase_type' => 'copa',
                        'name' => 'Copa',
                        'phase_order' => 2,
                        'status' => 'pending',
                        'config' => $config
                    ]);

                    // Ajustar seeds para la copa (empiezan después de la liguilla)
                    foreach ($copaStandings as $i => &$team) {
                        $team['original_position'] = $team['position'];
                        $team['position'] = $i + 1;
                    }

                    $copaStartDate = Carbon::parse($validated['start_date']);
                    $this->generateBrackets($copaPhase, $copaStandings, $config, $copaStartDate);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Liguilla generada exitosamente',
                'liguilla_phase' => $liguillaPhase->load('brackets.homeTeam', 'brackets.awayTeam'),
                'copa_phase' => $copaPhase?->load('brackets.homeTeam', 'brackets.awayTeam'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al generar la liguilla',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener bracket completo de una fase
     */
    public function getBracket(TournamentPhase $phase): JsonResponse
    {
        $brackets = $phase->brackets()
            ->with(['homeTeam', 'awayTeam', 'winner', 'matches.homeTeam', 'matches.awayTeam'])
            ->orderBy('round_number')
            ->orderBy('bracket_position')
            ->get()
            ->groupBy('round_number');

        return response()->json([
            'phase' => $phase,
            'rounds' => $brackets,
            'config' => $phase->full_config
        ]);
    }

    /**
     * Avanzar ganador automáticamente (después de completar partidos)
     */
    public function advanceWinner(PlayoffBracket $bracket): JsonResponse
    {
        if (!$bracket->isComplete()) {
            return response()->json([
                'error' => 'La serie no está completa. Faltan partidos por finalizar.'
            ], 400);
        }

        // Actualizar marcadores agregados
        $bracket->updateAggregateScores();

        // Intentar determinar ganador
        $winnerId = $bracket->determineWinner();

        if ($winnerId === null) {
            return response()->json([
                'message' => 'La serie está empatada. Se requiere definición por penales.',
                'bracket' => $bracket->load(['homeTeam', 'awayTeam']),
                'needs_penalty' => true,
                'home_aggregate' => $bracket->home_aggregate_score,
                'away_aggregate' => $bracket->away_aggregate_score
            ]);
        }

        $bracket->setWinner($winnerId);

        $winner = Team::find($winnerId);

        return response()->json([
            'message' => "Ganador: {$winner->name}",
            'bracket' => $bracket->fresh()->load(['homeTeam', 'awayTeam', 'winner', 'nextBracket']),
            'winner' => $winner
        ]);
    }

    /**
     * Establecer ganador manual (para desempates por penales)
     */
    public function setWinner(Request $request, PlayoffBracket $bracket): JsonResponse
    {
        $validated = $request->validate([
            'winner_team_id' => 'required|exists:teams,id'
        ]);

        $winnerId = $validated['winner_team_id'];

        // Verificar que el ganador sea uno de los dos equipos
        if ($winnerId != $bracket->home_team_id && $winnerId != $bracket->away_team_id) {
            return response()->json([
                'error' => 'El ganador debe ser uno de los equipos de la serie'
            ], 400);
        }

        $bracket->updateAggregateScores();
        $bracket->setWinner($winnerId);

        $winner = Team::find($winnerId);

        return response()->json([
            'message' => "Ganador establecido: {$winner->name}",
            'bracket' => $bracket->fresh()->load(['homeTeam', 'awayTeam', 'winner', 'nextBracket'])
        ]);
    }

    /**
     * Eliminar liguilla de un torneo
     */
    public function deleteLiguilla(Tournament $tournament): JsonResponse
    {
        try {
            DB::beginTransaction();

            $phases = $tournament->phases()->where('phase_type', '!=', 'regular')->get();

            foreach ($phases as $phase) {
                // Eliminar partidos de playoff
                $bracketIds = $phase->brackets()->pluck('id');
                Matchs::whereIn('playoff_bracket_id', $bracketIds)->delete();

                // Eliminar jornadas de playoff
                $phase->matchdays()->delete();

                // Eliminar brackets
                $phase->brackets()->delete();

                // Eliminar fase
                $phase->delete();
            }

            DB::commit();

            return response()->json([
                'message' => 'Liguilla eliminada exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al eliminar la liguilla',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todas las fases de un torneo
     */
    public function getPhases(Tournament $tournament): JsonResponse
    {
        $phases = $tournament->phases()
            ->with(['brackets.homeTeam', 'brackets.awayTeam', 'brackets.winner'])
            ->orderBy('phase_order')
            ->get();

        return response()->json($phases);
    }

    // ========================
    // Métodos privados
    // ========================

    /**
     * Calcular tabla de posiciones de un torneo
     */
    private function calculateStandings(Tournament $tournament, $teams): array
    {
        $standings = [];

        foreach ($teams as $team) {
            // Obtener partidos finalizados del torneo
            $matches = Matchs::whereHas('matchday', function ($q) use ($tournament) {
                $q->where('tournament_id', $tournament->id);
            })
            ->where('status', 'Finalizado')
            ->whereNull('playoff_bracket_id') // Solo fase regular
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                  ->orWhere('away_team_id', $team->id);
            })
            ->get();

            $wins = 0;
            $draws = 0;
            $losses = 0;
            $goalsFor = 0;
            $goalsAgainst = 0;

            foreach ($matches as $match) {
                if ($match->home_team_id == $team->id) {
                    $goalsFor += $match->home_score ?? 0;
                    $goalsAgainst += $match->away_score ?? 0;
                    if ($match->home_score > $match->away_score) $wins++;
                    elseif ($match->home_score == $match->away_score) $draws++;
                    else $losses++;
                } else {
                    $goalsFor += $match->away_score ?? 0;
                    $goalsAgainst += $match->home_score ?? 0;
                    if ($match->away_score > $match->home_score) $wins++;
                    elseif ($match->away_score == $match->home_score) $draws++;
                    else $losses++;
                }
            }

            $points = ($wins * 3) + $draws;

            $standings[] = [
                'team_id' => $team->id,
                'team_name' => $team->name,
                'team_logo' => $team->logo,
                'matches_played' => count($matches),
                'wins' => $wins,
                'draws' => $draws,
                'losses' => $losses,
                'goals_for' => $goalsFor,
                'goals_against' => $goalsAgainst,
                'goal_difference' => $goalsFor - $goalsAgainst,
                'points' => $points,
            ];
        }

        // Ordenar por: puntos desc, diferencia de goles desc, goles a favor desc
        usort($standings, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] - $a['points'];
            if ($a['goal_difference'] !== $b['goal_difference']) return $b['goal_difference'] - $a['goal_difference'];
            return $b['goals_for'] - $a['goals_for'];
        });

        // Agregar posición
        foreach ($standings as $i => &$s) {
            $s['position'] = $i + 1;
        }

        return $standings;
    }

    /**
     * Generar brackets para una fase de playoff
     */
    private function generateBrackets(TournamentPhase $phase, array $standings, array $config, Carbon $startDate): void
    {
        $teamCount = count($standings);
        $rounds = TournamentPhase::calculateRounds($teamCount);
        $isTwoLegs = $config['format'] === 'two_legs';
        $higherSeedHomeSecond = $config['higher_seed_home_second'] ?? true;

        // Crear brackets empezando por la final (hacia atrás)
        // Esto facilita vincular next_bracket_id
        $allBrackets = [];

        // Generar estructura de brackets por ronda (de la final hacia cuartos)
        for ($round = $rounds; $round >= 1; $round--) {
            $matchesInRound = (int) pow(2, $rounds - $round);
            $roundName = TournamentPhase::getRoundName($round, $teamCount);

            $isTheFinal = ($round === $rounds);
            $roundIsTwoLegs = $isTheFinal
                ? ($config['final_format'] === 'two_legs')
                : $isTwoLegs;

            for ($pos = 1; $pos <= $matchesInRound; $pos++) {
                $nextBracketId = null;
                $nextBracketPosition = null;

                // Vincular con el bracket de la siguiente ronda
                if ($round < $rounds) {
                    $nextRoundPos = (int) ceil($pos / 2);
                    $nextBracketKey = ($round + 1) . '-' . $nextRoundPos;
                    if (isset($allBrackets[$nextBracketKey])) {
                        $nextBracketId = $allBrackets[$nextBracketKey]->id;
                        $nextBracketPosition = ($pos % 2 === 1) ? 'home' : 'away';
                    }
                }

                $bracket = PlayoffBracket::create([
                    'tournament_phase_id' => $phase->id,
                    'round_number' => $round,
                    'round_name' => $roundName,
                    'bracket_position' => $pos,
                    'is_two_legs' => $roundIsTwoLegs,
                    'next_bracket_id' => $nextBracketId,
                    'next_bracket_position' => $nextBracketPosition,
                ]);

                $allBrackets[$round . '-' . $pos] = $bracket;
            }
        }

        // Asignar equipos a la primera ronda
        $firstRoundBrackets = collect($allBrackets)
            ->filter(fn($b) => $b->round_number === 1)
            ->sortBy('bracket_position');

        // Generar enfrentamientos: 1vs(N), 2vs(N-1), etc.
        $seedings = $this->generateSeedings($teamCount);

        foreach ($firstRoundBrackets as $bracket) {
            $pos = $bracket->bracket_position;
            $seedPair = $seedings[$pos - 1] ?? null;

            if ($seedPair) {
                $homeSeed = $seedPair['home'];
                $awaySeed = $seedPair['away'];

                $homeTeam = $standings[$homeSeed - 1] ?? null;
                $awayTeam = $standings[$awaySeed - 1] ?? null;

                if ($higherSeedHomeSecond && $isTwoLegs) {
                    // Mejor clasificado juega de visitante en ida, local en vuelta
                    // Así el "home" del bracket es el PEOR clasificado
                    $bracket->update([
                        'home_team_id' => $awayTeam['team_id'] ?? null,
                        'away_team_id' => $homeTeam['team_id'] ?? null,
                        'home_seed' => $awaySeed,
                        'away_seed' => $homeSeed,
                    ]);
                } else {
                    $bracket->update([
                        'home_team_id' => $homeTeam['team_id'] ?? null,
                        'away_team_id' => $awayTeam['team_id'] ?? null,
                        'home_seed' => $homeSeed,
                        'away_seed' => $awaySeed,
                    ]);
                }
            }
        }

        // Crear jornadas y partidos para la primera ronda
        $this->createPlayoffMatchdays($phase, 1, $startDate, $config);
    }

    /**
     * Generar seedings para brackets (1vsN, 2vsN-1, etc.)
     */
    private function generateSeedings(int $teamCount): array
    {
        $seedings = [];
        $halfCount = $teamCount / 2;

        for ($i = 0; $i < $halfCount; $i++) {
            $seedings[] = [
                'home' => $i + 1,
                'away' => $teamCount - $i
            ];
        }

        return $seedings;
    }

    /**
     * Crear jornadas y partidos para una ronda de playoff
     */
    private function createPlayoffMatchdays(TournamentPhase $phase, int $roundNumber, Carbon $startDate, array $config): void
    {
        $brackets = $phase->brackets()
            ->where('round_number', $roundNumber)
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->orderBy('bracket_position')
            ->get();

        if ($brackets->isEmpty()) {
            return;
        }

        $tournament = $phase->tournament;
        $isTwoLegs = $brackets->first()->is_two_legs;

        // Determinar la hora del partido según la ronda
        $rounds = TournamentPhase::calculateRounds($config['liguilla_teams']);
        $matchTimes = $config['match_times'] ?? [];
        $matchTime = '20:00';

        if ($roundNumber === $rounds) {
            $matchTime = $matchTimes['final'] ?? '18:00';
        } elseif ($roundNumber === $rounds - 1) {
            $matchTime = $matchTimes['semifinal'] ?? '20:00';
        } else {
            $matchTime = $matchTimes['cuartos'] ?? '20:00';
        }

        // Obtener último número de jornada del torneo
        $lastMatchday = Matchday::where('tournament_id', $tournament->id)
            ->orderBy('number', 'desc')
            ->first();
        $matchdayNumber = $lastMatchday ? $lastMatchday->number + 1 : 1;

        $roundName = $brackets->first()->round_name;

        // Crear jornada de ida
        $idaMatchday = Matchday::create([
            'tournament_id' => $tournament->id,
            'number' => $matchdayNumber,
            'name' => "{$roundName} - Ida",
            'date' => $startDate->format('Y-m-d'),
            'phase_type' => 'playoff',
            'tournament_phase_id' => $phase->id,
        ]);

        foreach ($brackets as $bracket) {
            // En ida: el "home" del bracket juega de local
            Matchs::create([
                'matchday_id' => $idaMatchday->id,
                'home_team_id' => $bracket->home_team_id,
                'away_team_id' => $bracket->away_team_id,
                'match_date' => $startDate->copy()->setTimeFromTimeString($matchTime),
                'home_score' => 0,
                'away_score' => 0,
                'status' => 'Programado',
                'playoff_bracket_id' => $bracket->id,
                'leg_number' => 1,
            ]);
        }

        if ($isTwoLegs) {
            // Crear jornada de vuelta
            $vueltaDate = $startDate->copy()->addDays($config['days_between_legs']);
            $matchdayNumber++;

            $vueltaMatchday = Matchday::create([
                'tournament_id' => $tournament->id,
                'number' => $matchdayNumber,
                'name' => "{$roundName} - Vuelta",
                'date' => $vueltaDate->format('Y-m-d'),
                'phase_type' => 'playoff',
                'tournament_phase_id' => $phase->id,
            ]);

            foreach ($brackets as $bracket) {
                // En vuelta: se invierten local/visitante
                Matchs::create([
                    'matchday_id' => $vueltaMatchday->id,
                    'home_team_id' => $bracket->away_team_id,
                    'away_team_id' => $bracket->home_team_id,
                    'match_date' => $vueltaDate->copy()->setTimeFromTimeString($matchTime),
                    'home_score' => 0,
                    'away_score' => 0,
                    'status' => 'Programado',
                    'playoff_bracket_id' => $bracket->id,
                    'leg_number' => 2,
                ]);
            }
        }
    }

    /**
     * Crear partidos para la siguiente ronda cuando los ganadores se determinan
     */
    public function createNextRoundMatches(Request $request, TournamentPhase $phase): JsonResponse
    {
        $validated = $request->validate([
            'round_number' => 'required|integer|min:2'
        ]);

        $roundNumber = $validated['round_number'];
        $config = $phase->full_config;

        // Verificar que la ronda anterior esté completa
        $prevRoundBrackets = $phase->brackets()
            ->where('round_number', $roundNumber - 1)
            ->get();

        $allHaveWinners = $prevRoundBrackets->every(fn($b) => $b->winner_team_id !== null);

        if (!$allHaveWinners) {
            return response()->json([
                'error' => 'La ronda anterior no está completa. Todos los brackets deben tener ganador.'
            ], 400);
        }

        // Calcular fecha de inicio de la nueva ronda
        $lastMatchday = Matchday::where('tournament_id', $phase->tournament_id)
            ->where('tournament_phase_id', $phase->id)
            ->orderBy('date', 'desc')
            ->first();

        $startDate = $lastMatchday
            ? Carbon::parse($lastMatchday->date)->addDays($config['days_between_rounds'])
            : Carbon::now()->addDays($config['days_between_rounds']);

        try {
            DB::beginTransaction();

            $this->createPlayoffMatchdays($phase, $roundNumber, $startDate, $config);

            DB::commit();

            return response()->json([
                'message' => 'Partidos de la siguiente ronda creados exitosamente',
                'phase' => $phase->fresh()->load('brackets.homeTeam', 'brackets.awayTeam', 'brackets.winner')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Error al crear partidos',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
