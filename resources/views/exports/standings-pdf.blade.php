<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tabla de Posiciones - {{ $tournament->name }}</title>
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
        
        .standings-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #E5E7EB;
            margin-bottom: 20px;
        }
        
        .standings-table thead {
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
        }
        
        .standings-table th {
            padding: 12px 8px;
            text-align: center;
            font-weight: bold;
        }
        
        .standings-table td {
            padding: 10px 8px;
            text-align: center;
            border-bottom: 1px solid #F3F4F6;
        }
        
        .standings-table tr.position-1 {
            background: #ECFDF5;
            border-left: 4px solid #10B981;
        }
        
        .standings-table tr.position-2,
        .standings-table tr.position-3,
        .standings-table tr.position-4 {
            background: #F0FDF4;
            border-left: 4px solid #86EFAC;
        }
        
        .standings-table .team-name {
            text-align: left;
            font-weight: bold;
        }
        
        .standings-table .points {
            font-weight: bold;
            color: #10B981;
            font-size: 14px;
        }
        
        .legend {
            margin-top: 30px;
            padding: 15px;
            background: #F9FAFB;
            border-radius: 8px;
        }
        
        .legend h3 {
            margin-bottom: 10px;
            color: #1F2937;
        }
        
        .legend-item {
            display: inline-block;
            margin-right: 20px;
            margin-bottom: 5px;
        }
        
        .footer {
            margin-top: 40px;
            text-align: center;
            color: #9CA3AF;
            font-size: 10px;
            border-top: 1px solid #E5E7EB;
            padding-top: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Tabla de Posiciones</h1>
        <div class="subtitle">{{ $tournament->name }}</div>
    </div>
    
    <table class="standings-table">
        <thead>
            <tr>
                <th width="8%">Pos</th>
                <th width="30%">Equipo</th>
                <th width="8%">PJ</th>
                <th width="8%">PG</th>
                <th width="8%">PE</th>
                <th width="8%">PP</th>
                <th width="8%">GF</th>
                <th width="8%">GC</th>
                <th width="8%">DG</th>
                <th width="8%">Pts</th>
            </tr>
        </thead>
        <tbody>
            @foreach($teams as $index => $team)
            <tr class="position-{{ $index + 1 }}">
                <td>{{ $index + 1 }}</td>
                <td class="team-name">{{ $team->name }}</td>
                <td>{{ $team->matches_played }}</td>
                <td>{{ $team->wins }}</td>
                <td>{{ $team->draws }}</td>
                <td>{{ $team->losses }}</td>
                <td>{{ $team->goals_for }}</td>
                <td>{{ $team->goals_against }}</td>
                <td>{{ $team->goals_for - $team->goals_against }}</td>
                <td class="points">{{ $team->points }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    
    <div class="legend">
        <h3>Leyenda</h3>
        <div class="legend-item"><strong>PJ:</strong> Partidos Jugados</div>
        <div class="legend-item"><strong>PG:</strong> Partidos Ganados</div>
        <div class="legend-item"><strong>PE:</strong> Partidos Empatados</div>
        <div class="legend-item"><strong>PP:</strong> Partidos Perdidos</div>
        <div class="legend-item"><strong>GF:</strong> Goles a Favor</div>
        <div class="legend-item"><strong>GC:</strong> Goles en Contra</div>
        <div class="legend-item"><strong>DG:</strong> Diferencia de Goles</div>
        <div class="legend-item"><strong>Pts:</strong> Puntos</div>
    </div>
    
    <div class="footer">
        Documento generado el {{ $generatedDate }} - Sistema de Gestión de Torneos de Fútbol
    </div>
</body>
</html>Compare this snippet from torneo-futbol-api/resources/views/exports/fixture-pdf.blade.php: