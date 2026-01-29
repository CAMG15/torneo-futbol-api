<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ExportController extends Controller
{
    /**
     * Obtener tabla de posiciones (JSON público)
     */
    public function standings(Tournament $tournament)
    {
        $teams = $this->getStandingsData($tournament);
        $topScorers = $this->getTopScorers($tournament);

        return response()->json([
            'tournament' => $tournament->name,
            'standings' => $teams,
            'top_scorers' => $topScorers
        ]);
    }

    /**
     * Obtener fixture del torneo (JSON público)
     */
    public function fixture(Tournament $tournament)
    {
        $tournament->load(['matchdays.matches.homeTeam', 'matchdays.matches.awayTeam']);

        $fixture = $tournament->matchdays->map(function ($matchday) {
            return [
                'matchday' => $matchday->number,
                'name' => $matchday->name,
                'date' => $matchday->date,
                'matches' => $matchday->matches->map(function ($match) {
                    return [
                        'id' => $match->id,
                        'home_team' => $match->homeTeam->name ?? 'Por definir',
                        'away_team' => $match->awayTeam->name ?? 'Por definir',
                        'home_score' => $match->home_score,
                        'away_score' => $match->away_score,
                        'date' => $match->match_date,
                        'location' => $match->location,
                        'status' => $match->status,
                    ];
                })
            ];
        });

        return response()->json([
            'tournament' => $tournament->name,
            'fixture' => $fixture
        ]);
    }

    /**
     * Exportar torneo completo a PDF
     */
    public function tournamentPdf(Tournament $tournament)
    {
        $tournament->load(['matchdays.matches.homeTeam', 'matchdays.matches.awayTeam']);
        $standings = $this->getStandingsData($tournament);
        $topScorers = $this->getTopScorers($tournament);

        $data = [
            'tournament' => $tournament,
            'standings' => $standings,
            'topScorers' => $topScorers,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ];

        $pdf = Pdf::loadView('exports.tournament', $data);
        $pdf->setPaper('a4', 'portrait');

        $filename = 'torneo_' . str_replace(' ', '_', $tournament->name) . '_' . now()->format('Ymd') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Exportar torneo a Excel
     */
    public function tournamentExcel(Tournament $tournament)
    {
        $tournament->load(['matchdays.matches.homeTeam', 'matchdays.matches.awayTeam']);
        $standings = $this->getStandingsData($tournament);

        $spreadsheet = new Spreadsheet();

        // Hoja 1: Tabla de posiciones
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tabla de Posiciones');

        // Encabezados
        $headers = ['Pos', 'Equipo', 'PJ', 'PG', 'PE', 'PP', 'GF', 'GC', 'DG', 'Pts'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }

        // Estilos para encabezados
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Datos
        $row = 2;
        foreach ($standings as $index => $team) {
            $sheet->setCellValue('A' . $row, $index + 1);
            $sheet->setCellValue('B' . $row, $team['name']);
            $sheet->setCellValue('C' . $row, $team['matches_played']);
            $sheet->setCellValue('D' . $row, $team['wins']);
            $sheet->setCellValue('E' . $row, $team['draws']);
            $sheet->setCellValue('F' . $row, $team['losses']);
            $sheet->setCellValue('G' . $row, $team['goals_for']);
            $sheet->setCellValue('H' . $row, $team['goals_against']);
            $sheet->setCellValue('I' . $row, $team['goal_difference']);
            $sheet->setCellValue('J' . $row, $team['points']);
            $row++;
        }

        // Bordes
        $lastRow = $row - 1;
        $sheet->getStyle("A1:J{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ]
        ]);

        // Autoajustar columnas
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Hoja 2: Fixture
        $fixtureSheet = $spreadsheet->createSheet();
        $fixtureSheet->setTitle('Fixture');

        $fixtureSheet->setCellValue('A1', 'Jornada');
        $fixtureSheet->setCellValue('B1', 'Fecha');
        $fixtureSheet->setCellValue('C1', 'Local');
        $fixtureSheet->setCellValue('D1', 'Resultado');
        $fixtureSheet->setCellValue('E1', 'Visitante');
        $fixtureSheet->setCellValue('F1', 'Estado');

        $fixtureSheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ]
        ]);

        $row = 2;
        foreach ($tournament->matchdays as $matchday) {
            foreach ($matchday->matches as $match) {
                $fixtureSheet->setCellValue('A' . $row, $matchday->name);
                $fixtureSheet->setCellValue('B' . $row, $match->match_date?->format('d/m/Y H:i'));
                $fixtureSheet->setCellValue('C' . $row, $match->homeTeam->name ?? 'TBD');
                $fixtureSheet->setCellValue('D' . $row, $match->home_score . ' - ' . $match->away_score);
                $fixtureSheet->setCellValue('E' . $row, $match->awayTeam->name ?? 'TBD');
                $fixtureSheet->setCellValue('F' . $row, $match->status);
                $row++;
            }
        }

        foreach (range('A', 'F') as $col) {
            $fixtureSheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Generar archivo
        $writer = new Xlsx($spreadsheet);
        $filename = 'torneo_' . str_replace(' ', '_', $tournament->name) . '_' . now()->format('Ymd') . '.xlsx';
        $tempPath = storage_path('app/public/' . $filename);

        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Exportar lista de jugadores a Excel
     */
    public function playersExcel(Request $request)
    {
        $players = Player::with('currentTeams')
            ->where('active', true)
            ->orderBy('lastname')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Jugadores');

        // Encabezados
        $headers = ['ID', 'Nombre', 'Apellido', 'Apodo', 'Posición', 'Equipo(s)', 'Goles', 'Asistencias', 'T. Amarillas', 'T. Rojas'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }

        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ]
        ]);

        $row = 2;
        foreach ($players as $player) {
            $teams = $player->currentTeams->pluck('name')->implode(', ');

            $sheet->setCellValue('A' . $row, $player->id);
            $sheet->setCellValue('B' . $row, $player->name);
            $sheet->setCellValue('C' . $row, $player->lastname);
            $sheet->setCellValue('D' . $row, $player->nickname);
            $sheet->setCellValue('E' . $row, $player->position);
            $sheet->setCellValue('F' . $row, $teams ?: 'Sin equipo');
            $sheet->setCellValue('G' . $row, $player->goals ?? 0);
            $sheet->setCellValue('H' . $row, $player->assists ?? 0);
            $sheet->setCellValue('I' . $row, $player->yellow_cards ?? 0);
            $sheet->setCellValue('J' . $row, $player->red_cards ?? 0);
            $row++;
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'jugadores_' . now()->format('Ymd') . '.xlsx';
        $tempPath = storage_path('app/public/' . $filename);

        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Obtener datos de tabla de posiciones
     */
    private function getStandingsData(Tournament $tournament): array
    {
        $teamIds = DB::table('tournament_team')
            ->where('tournament_id', $tournament->id)
            ->pluck('team_id');

        $teams = Team::whereIn('id', $teamIds)->get();

        $standings = $teams->map(function ($team) use ($tournament) {
            $homeMatches = DB::table('matches')
                ->join('matchdays', 'matches.matchday_id', '=', 'matchdays.id')
                ->where('matchdays.tournament_id', $tournament->id)
                ->where('matches.home_team_id', $team->id)
                ->where('matches.status', 'Finalizado')
                ->select('matches.*')
                ->get();

            $awayMatches = DB::table('matches')
                ->join('matchdays', 'matches.matchday_id', '=', 'matchdays.id')
                ->where('matchdays.tournament_id', $tournament->id)
                ->where('matches.away_team_id', $team->id)
                ->where('matches.status', 'Finalizado')
                ->select('matches.*')
                ->get();

            $won = 0;
            $drawn = 0;
            $lost = 0;
            $goalsFor = 0;
            $goalsAgainst = 0;

            foreach ($homeMatches as $match) {
                $goalsFor += $match->home_score ?? 0;
                $goalsAgainst += $match->away_score ?? 0;

                if ($match->home_score > $match->away_score) {
                    $won++;
                } elseif ($match->home_score == $match->away_score) {
                    $drawn++;
                } else {
                    $lost++;
                }
            }

            foreach ($awayMatches as $match) {
                $goalsFor += $match->away_score ?? 0;
                $goalsAgainst += $match->home_score ?? 0;

                if ($match->away_score > $match->home_score) {
                    $won++;
                } elseif ($match->away_score == $match->home_score) {
                    $drawn++;
                } else {
                    $lost++;
                }
            }

            $played = $won + $drawn + $lost;
            $points = ($won * 3) + $drawn;
            $goalDifference = $goalsFor - $goalsAgainst;

            return [
                'id' => $team->id,
                'name' => $team->name,
                'matches_played' => $played,
                'wins' => $won,
                'draws' => $drawn,
                'losses' => $lost,
                'goals_for' => $goalsFor,
                'goals_against' => $goalsAgainst,
                'goal_difference' => $goalDifference,
                'points' => $points,
            ];
        });

        return $standings->sortByDesc(function ($team) {
            return [$team['points'], $team['goal_difference'], $team['goals_for']];
        })->values()->toArray();
    }

    /**
     * Obtener goleadores del torneo
     */
    private function getTopScorers(Tournament $tournament, int $limit = 10): array
    {
        return DB::table('match_events')
            ->join('matches', 'match_events.match_id', '=', 'matches.id')
            ->join('matchdays', 'matches.matchday_id', '=', 'matchdays.id')
            ->join('players', 'match_events.player_id', '=', 'players.id')
            ->join('teams', 'match_events.team_id', '=', 'teams.id')
            ->where('matchdays.tournament_id', $tournament->id)
            ->where('match_events.event_type', 'Gol')
            ->select(
                'players.id',
                'players.name',
                'players.lastname',
                'players.photo',
                'teams.name as team_name',
                DB::raw('COUNT(*) as goals')
            )
            ->groupBy('players.id', 'players.name', 'players.lastname', 'players.photo', 'teams.name')
            ->orderByDesc('goals')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
