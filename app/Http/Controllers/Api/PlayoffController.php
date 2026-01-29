<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Matchday;
use App\Models\Matchs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PlayoffController extends Controller
{
    /**
     * Generar llave de playoffs
     */
    public function generatePlayoffs(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'playoff_type' => 'required|in:Cuartos,Semifinal,Final,Completo',
            'num_teams' => 'required|integer|in:4,8,16',
            'start_date' => 'required|date',
            'match_time' => 'required|date_format:H:i',
            'days_between_rounds' => 'required|integer|min:1',
            'location' => 'nullable|string',
            'two_legs' => 'boolean' // Ida y vuelta
        ]);

        try {
            DB::beginTransaction();

            // Obtener equipos clasificados por posición
            $qualifiedTeams = DB::table('tournament_team')
                ->join('teams', 'tournament_team.team_id', '=', 'teams.id')
                ->where('tournament_team.tournament_id', $tournament->id)
                ->orderBy('teams.points', 'desc')
                ->orderByRaw('(teams.goals_for - teams.goals_against) desc')
                ->orderBy('teams.goals_for', 'desc')
                ->limit($validated['num_teams'])
                ->pluck('teams.id')
                ->toArray();

            if (count($qualifiedTeams) < $validated['num_teams']) {
                return response()->json([
                    'error' => 'No hay suficientes equipos clasificados'
                ], 400);
            }

            // Generar emparejamientos
            $matches = $this->generatePlayoffBracket($qualifiedTeams, $validated['num_teams']);
            
            $startDate = Carbon::parse($validated['start_date']);
            $matchTime = Carbon::parse($validated['match_time']);
            $roundNames = $this->getRoundNames($validated['num_teams']);
            
            foreach ($roundNames as $roundIndex => $roundName) {
                $roundMatches = $matches[$roundIndex];
                
                // Crear jornada para la ronda
                $matchday = Matchday::create([
                    'tournament_id' => $tournament->id,
                    'number' => 900 + $roundIndex, // Números altos para distinguir de fase regular
                    'name' => $roundName,
                    'date' => $startDate->format('Y-m-d')
                ]);

                // Crear partidos de ida
                foreach ($roundMatches as $matchPair) {
                    Matchs::create([
                        'matchday_id' => $matchday->id,
                        'home_team_id' => $matchPair['home'],
                        'away_team_id' => $matchPair['away'],
                        'match_date' => $startDate->copy()->setTimeFrom($matchTime),
                        'location' => $validated['location'] ?? null,
                        'status' => 'Programado'
                    ]);
                }

                // Si es ida y vuelta, crear partidos de vuelta
                if ($validated['two_legs'] ?? false) {
                    $matchdayReturn = Matchday::create([
                        'tournament_id' => $tournament->id,
                        'number' => 950 + $roundIndex,
                        'name' => $roundName . ' (Vuelta)',
                        'date' => $startDate->copy()->addDays($validated['days_between_rounds'])->format('Y-m-d')
                    ]);

                    foreach ($roundMatches as $matchPair) {
                        Matchs::create([
                            'matchday_id' => $matchdayReturn->id,
                            'home_team_id' => $matchPair['away'], // Invertir local/visitante
                            'away_team_id' => $matchPair['home'],
                            'match_date' => $startDate->copy()
                                ->addDays($validated['days_between_rounds'])
                                ->setTimeFrom($matchTime),
                            'location' => $validated['location'] ?? null,
                            'status' => 'Programado'
                        ]);
                    }
                }

                // Avanzar fecha para siguiente ronda
                $daysToAdd = $validated['two_legs'] ? 
                    $validated['days_between_rounds'] * 2 : 
                    $validated['days_between_rounds'];
                $startDate->addDays($daysToAdd);
            }

            DB::commit();

            return response()->json([
                'message' => 'Playoffs generados exitosamente',
                'rounds_created' => count($roundNames)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generar bracket de playoffs
     */
    private function generatePlayoffBracket($teams, $numTeams)
    {
        $bracket = [];
        
        // Emparejamientos: 1 vs último, 2 vs penúltimo, etc.
        $numRounds = log($numTeams, 2); // 8 equipos = 3 rondas
        
        // Primera ronda
        $firstRound = [];
        for ($i = 0; $i < $numTeams / 2; $i++) {
            $firstRound[] = [
                'home' => $teams[$i],
                'away' => $teams[$numTeams - 1 - $i]
            ];
        }
        
        $bracket[] = $firstRound;
        
        // Crear estructura para rondas siguientes (se llenarán cuando avancen equipos)
        for ($round = 1; $round < $numRounds; $round++) {
            $numMatchesInRound = pow(2, $numRounds - $round - 1);
            $roundMatches = [];
            
            for ($i = 0; $i < $numMatchesInRound; $i++) {
                $roundMatches[] = [
                    'home' => null, // Se define cuando termine ronda anterior
                    'away' => null
                ];
            }
            
            $bracket[] = $roundMatches;
        }
        
        return $bracket;
    }

    /**
     * Obtener nombres de rondas según número de equipos
     */
    private function getRoundNames($numTeams)
    {
        switch ($numTeams) {
            case 4:
                return ['Semifinales', 'Final'];
            case 8:
                return ['Cuartos de Final', 'Semifinales', 'Final'];
            case 16:
                return ['Octavos de Final', 'Cuartos de Final', 'Semifinales', 'Final'];
            default:
                return ['Eliminatoria'];
        }
    }

    /**
     * Avanzar equipo ganador a siguiente ronda
     */
    public function advanceTeam(Request $request)
    {
        $validated = $request->validate([
            'match_id' => 'required|exists:matches,id',
            'winner_team_id' => 'required|exists:teams,id',
            'next_match_id' => 'required|exists:matches,id',
            'position' => 'required|in:home,away'
        ]);

        try {
            DB::beginTransaction();

            $nextMatch = Matchs::findOrFail($validated['next_match_id']);
            
            if ($validated['position'] === 'home') {
                $nextMatch->home_team_id = $validated['winner_team_id'];
            } else {
                $nextMatch->away_team_id = $validated['winner_team_id'];
            }
            
            $nextMatch->save();

            DB::commit();

            return response()->json([
                'message' => 'Equipo avanzado a siguiente ronda',
                'match' => $nextMatch->load(['homeTeam', 'awayTeam'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener bracket completo de playoffs
     */
    public function getPlayoffBracket(Tournament $tournament)
    {
        $playoffMatchdays = Matchday::where('tournament_id', $tournament->id)
            ->where('number', '>=', 900)
            ->orderBy('number')
            ->with(['matches.homeTeam', 'matches.awayTeam'])
            ->get();

        // Organizar por rondas
        $rounds = [];
        foreach ($playoffMatchdays as $matchday) {
            $roundNumber = floor($matchday->number / 50);
            if (!isset($rounds[$roundNumber])) {
                $rounds[$roundNumber] = [
                    'name' => $matchday->name,
                    'matches' => []
                ];
            }
            $rounds[$roundNumber]['matches'] = array_merge(
                $rounds[$roundNumber]['matches'],
                $matchday->matches->toArray()
            );
        }

        return response()->json([
            'tournament' => $tournament->name,
            'rounds' => array_values($rounds)
        ]);
    }

    /**
     * Generar partido por tercer lugar
     */
    public function generateThirdPlaceMatch(Request $request, Tournament $tournament)
    {
        $validated = $request->validate([
            'semifinal_matches' => 'required|array|size:2',
            'semifinal_matches.*' => 'exists:matches,id',
            'match_date' => 'required|date',
            'location' => 'nullable|string'
        ]);

        try {
            // Crear jornada especial
            $matchday = Matchday::create([
                'tournament_id' => $tournament->id,
                'number' => 999,
                'name' => 'Tercer Lugar',
                'date' => Carbon::parse($validated['match_date'])->format('Y-m-d')
            ]);

            // Crear partido (equipos se asignarán después de las semis)
            $match = Matchs::create([
                'matchday_id' => $matchday->id,
                'home_team_id' => null, // Se define después
                'away_team_id' => null,
                'match_date' => $validated['match_date'],
                'location' => $validated['location'] ?? null,
                'status' => 'Programado'
            ]);

            return response()->json([
                'message' => 'Partido por tercer lugar creado',
                'match' => $match
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
