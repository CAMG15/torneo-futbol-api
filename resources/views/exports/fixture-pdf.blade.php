<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Fixture - {{ $tournament->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1F2937;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #10B981;
            padding-bottom: 20px;
        }
        
        .header h1 {
            font-size: 24px;
            color: #10B981;
            margin-bottom: 10px;
        }
        
        .header .subtitle {
            font-size: 14px;
            color: #6B7280;
        }
        
        .info-box {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .info-box table {
            width: 100%;
        }
        
        .info-box td {
            padding: 5px 10px;
        }
        
        .info-box strong {
            color: #1F2937;
        }
        
        .matchday {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .matchday-header {
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            padding: 12px 15px;
            border-radius: 8px 8px 0 0;
            font-weight: bold;
            font-size: 14px;
        }
        
        .matchday-date {
            float: right;
            font-weight: normal;
        }
        
        .matches-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #E5E7EB;
            border-top: none;
        }
        
        .matches-table th {
            background: #F9FAFB;
            padding: 10px;
            text-align: left;
            font-weight: bold;
            color: #6B7280;
            border-bottom: 2px solid #E5E7EB;
        }
        
        .matches-table td {
            padding: 10px;
            border-bottom: 1px solid #F3F4F6;
        }
        
        .matches-table tr:last-child td {
            border-bottom: none;
        }
        
        .matches-table tr:hover {
            background: #F9FAFB;
        }
        
        .team-name {
            font-weight: bold;
            color: #1F2937;
        }
        
        .vs {
            text-align: center;
            color: #9CA3AF;
            font-weight: bold;
            font-size: 10px;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            color: #9CA3AF;
            font-size: 10px;
            border-top: 1px solid #E5E7EB;
            padding-top: 15px;
        }
        
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $tournament->name }}</h1>
        <div class="subtitle">Fixture Completo del Torneo</div>
    </div>
    
    <div class="info-box">
        <table>
            <tr>
                <td><strong>Tipo:</strong> {{ $tournament->type }}</td>
                <td><strong>Fecha de Inicio:</strong> {{ \Carbon\Carbon::parse($tournament->start_date)->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td><strong>Estado:</strong> {{ $tournament->status }}</td>
                <td><strong>Generado:</strong> {{ $generatedDate }}</td>
            </tr>
            <tr>
                <td><strong>Total Jornadas:</strong> {{ $tournament->matchdays->count() }}</td>
                <td><strong>Total Partidos:</strong> {{ $tournament->matchdays->sum(function($md) { return $md->matches->count(); }) }}</td>
            </tr>
        </table>
    </div>
    
    @foreach($tournament->matchdays as $index => $matchday)
        @if($index > 0 && $index % 3 == 0)
            <div class="page-break"></div>
        @endif
        
        <div class="matchday">
            <div class="matchday-header">
                {{ $matchday->name }}
                <span class="matchday-date">{{ \Carbon\Carbon::parse($matchday->date)->format('d/m/Y') }}</span>
            </div>
            
            <table class="matches-table">
                <thead>
                    <tr>
                        <th width="35%">Equipo Local</th>
                        <th width="10%"></th>
                        <th width="35%">Equipo Visitante</th>
                        <th width="10%">Hora</th>
                        <th width="10%">Ubicación</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($matchday->matches as $match)
                    <tr>
                        <td class="team-name">{{ $match->homeTeam->name }}</td>
                        <td class="vs">VS</td>
                        <td class="team-name">{{ $match->awayTeam->name }}</td>
                        <td>{{ \Carbon\Carbon::parse($match->match_date)->format('H:i') }}</td>
                        <td>{{ $match->location ?? '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
    
    <div class="footer">
        Documento generado automáticamente el {{ $generatedDate }} - Sistema de Gestión de Torneos de Fútbol
    </div>
</body>
</html>