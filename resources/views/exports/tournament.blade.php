<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tournament->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #4472C4;
        }
        .header h1 {
            font-size: 24px;
            color: #4472C4;
            margin-bottom: 10px;
        }
        .header .subtitle {
            font-size: 14px;
            color: #666;
        }
        .header .meta {
            font-size: 11px;
            color: #888;
            margin-top: 5px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 16px;
            color: #4472C4;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            padding: 8px 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #4472C4;
            color: white;
            font-weight: bold;
            text-align: center;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f5f5f5;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .position {
            font-weight: bold;
            color: #4472C4;
        }
        .top-3 {
            background-color: #e8f4e8 !important;
        }
        .bottom-3 {
            background-color: #fde8e8 !important;
        }
        .matchday {
            margin-bottom: 20px;
        }
        .matchday-title {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
            padding: 5px 10px;
            background-color: #f0f0f0;
        }
        .match-row {
            display: table;
            width: 100%;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .match-teams {
            display: table-cell;
            width: 70%;
        }
        .match-score {
            display: table-cell;
            width: 15%;
            text-align: center;
            font-weight: bold;
        }
        .match-status {
            display: table-cell;
            width: 15%;
            text-align: right;
            font-size: 10px;
            color: #888;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 10px;
            color: #888;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $tournament->name }}</h1>
        <div class="subtitle">{{ $tournament->type }} - {{ $tournament->status }}</div>
        <div class="meta">
            Fecha: {{ \Carbon\Carbon::parse($tournament->start_date)->format('d/m/Y') }}
            @if($tournament->end_date)
                - {{ \Carbon\Carbon::parse($tournament->end_date)->format('d/m/Y') }}
            @endif
        </div>
    </div>

    <!-- Tabla de Posiciones -->
    <div class="section">
        <h2 class="section-title">Tabla de Posiciones</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%">Pos</th>
                    <th style="width: 30%">Equipo</th>
                    <th style="width: 8%">PJ</th>
                    <th style="width: 8%">PG</th>
                    <th style="width: 8%">PE</th>
                    <th style="width: 8%">PP</th>
                    <th style="width: 8%">GF</th>
                    <th style="width: 8%">GC</th>
                    <th style="width: 8%">DG</th>
                    <th style="width: 9%">Pts</th>
                </tr>
            </thead>
            <tbody>
                @foreach($standings as $index => $team)
                    <tr class="{{ $index < 3 ? 'top-3' : ($index >= count($standings) - 3 ? 'bottom-3' : '') }}">
                        <td class="text-center position">{{ $index + 1 }}</td>
                        <td>{{ $team['name'] }}</td>
                        <td class="text-center">{{ $team['matches_played'] }}</td>
                        <td class="text-center">{{ $team['wins'] }}</td>
                        <td class="text-center">{{ $team['draws'] }}</td>
                        <td class="text-center">{{ $team['losses'] }}</td>
                        <td class="text-center">{{ $team['goals_for'] }}</td>
                        <td class="text-center">{{ $team['goals_against'] }}</td>
                        <td class="text-center">{{ $team['goal_difference'] > 0 ? '+' : '' }}{{ $team['goal_difference'] }}</td>
                        <td class="text-center"><strong>{{ $team['points'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <!-- Goleadores -->
    @if(!empty($topScorers))
    <div class="section">
        <h2 class="section-title">Goleadores</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 10%">Pos</th>
                    <th style="width: 40%">Jugador</th>
                    <th style="width: 35%">Equipo</th>
                    <th style="width: 15%">Goles</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topScorers as $index => $scorer)
                    <tr>
                        <td class="text-center position">{{ $index + 1 }}</td>
                        <td>{{ $scorer->name }} {{ $scorer->lastname }}</td>
                        <td>{{ $scorer->team_name }}</td>
                        <td class="text-center"><strong>{{ $scorer->goals }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="page-break"></div>

    <!-- Fixture -->
    <div class="section">
        <h2 class="section-title">Fixture Completo</h2>
        @foreach($tournament->matchdays as $matchday)
            <div class="matchday">
                <div class="matchday-title">
                    {{ $matchday->name }} - {{ \Carbon\Carbon::parse($matchday->date)->format('d/m/Y') }}
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 30%">Local</th>
                            <th style="width: 15%">Resultado</th>
                            <th style="width: 30%">Visitante</th>
                            <th style="width: 25%">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($matchday->matches as $match)
                            <tr>
                                <td class="text-right">{{ $match->homeTeam->name ?? 'Por definir' }}</td>
                                <td class="text-center">
                                    @if($match->status === 'Finalizado' || $match->status === 'En Vivo')
                                        <strong>{{ $match->home_score }} - {{ $match->away_score }}</strong>
                                    @else
                                        vs
                                    @endif
                                </td>
                                <td>{{ $match->awayTeam->name ?? 'Por definir' }}</td>
                                <td class="text-center">
                                    @if($match->status === 'Finalizado')
                                        Finalizado
                                    @elseif($match->status === 'En Vivo')
                                        <strong style="color: #e74c3c;">EN VIVO</strong>
                                    @else
                                        {{ $match->match_date ? \Carbon\Carbon::parse($match->match_date)->format('H:i') : 'Por definir' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <div class="footer">
        <p>Documento generado el {{ $generatedAt }}</p>
        <p>Sistema de Gestión de Torneos de Fútbol</p>
    </div>
</body>
</html>
