# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Torneo Fútbol API** - API REST para gestión de torneos de fútbol construida con Laravel 10.

## Comandos Frecuentes

```bash
# Iniciar servidor de desarrollo
php artisan serve

# Ejecutar migraciones
php artisan migrate

# Limpiar caché
php artisan cache:clear && php artisan config:clear && php artisan route:clear

# Listar rutas
php artisan route:list

# Generar key de aplicación
php artisan key:generate

# Crear storage link
php artisan storage:link
```

## Estructura del Proyecto

```
app/
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php        # Autenticación (login, register, logout)
│   │   ├── PlayerController.php      # CRUD jugadores
│   │   ├── TeamController.php        # CRUD equipos
│   │   ├── TournamentController.php  # CRUD torneos + fixture
│   │   ├── MatchController.php       # CRUD partidos + eventos
│   │   ├── LiguillaController.php    # Sistema de playoffs/liguilla
│   │   ├── AdvertisementController.php # Publicidad
│   │   └── ExportController.php      # Exportación PDF/Excel + standings
│   └── Requests/                     # Form Requests para validación
├── Models/
│   ├── Tournament.php       # Torneo
│   ├── TournamentPhase.php  # Fases (regular, liguilla, copa)
│   ├── PlayoffBracket.php   # Brackets de eliminación
│   ├── Team.php             # Equipo
│   ├── Player.php           # Jugador
│   ├── Matchday.php         # Jornada
│   ├── Matchs.php           # Partido (tabla: matches)
│   ├── MatchEvent.php       # Eventos del partido
│   └── MatchdaySchedule.php # Configuración de horarios
└── Exceptions/
    └── Handler.php          # Manejo centralizado de errores API
```

## Base de Datos

**Motor:** MySQL
**Base de datos:** MiFutbol
**Timezone:** America/Mexico_City

### Tablas Principales
- `tournaments` - Torneos (incluye campo `type`)
- `tournament_phases` - Fases del torneo (regular, liguilla, copa)
- `playoff_brackets` - Brackets de eliminación directa
- `teams` - Equipos
- `players` - Jugadores
- `matchdays` - Jornadas (incluye `phase_type`, `tournament_phase_id`)
- `matches` - Partidos (incluye `playoff_bracket_id`, `leg_number`)
- `match_events` - Eventos (goles, tarjetas, etc.)
- `matchday_schedules` - Configuraciones de horarios
- `matchday_schedule_slots` - Slots de horarios

### Relaciones Clave
- Tournament hasMany TournamentPhase
- TournamentPhase hasMany PlayoffBracket
- PlayoffBracket hasMany Matchs (ida/vuelta)
- Tournament hasMany Matchday
- Matchday hasMany Matchs
- Matchs belongsTo Team (home_team, away_team)
- Matchs hasMany MatchEvent

## API Endpoints

### Rutas Públicas (sin autenticación)

```
# Jugadores
GET    /api/public/players
GET    /api/public/players/{id}
GET    /api/public/players/statistics/top

# Equipos
GET    /api/public/teams
GET    /api/public/teams/{id}

# Partidos
GET    /api/public/matches
GET    /api/public/matches/{id}
GET    /api/public/matches/upcoming/list
GET    /api/public/matches/live/current

# Torneos
GET    /api/public/tournaments
GET    /api/public/tournaments/{id}
GET    /api/public/tournaments/{id}/teams

# Liguilla (público)
GET    /api/public/tournaments/{id}/standings    # Tabla con clasificados + top_scorers
GET    /api/public/tournaments/{id}/phases       # Fases del torneo
GET    /api/public/phases/{id}/bracket           # Bracket de una fase

# Exportación
GET    /api/public/tournaments/{id}/export/standings  # JSON con standings + top_scorers
GET    /api/public/tournaments/{id}/export/fixture    # Fixture JSON
```

### Rutas Protegidas (requieren Bearer token)

```
# Liguilla (admin)
POST   /api/tournaments/{id}/generate-liguilla   # Generar brackets
DELETE /api/tournaments/{id}/liguilla            # Eliminar liguilla
POST   /api/brackets/{id}/advance-winner         # Avanzar ganador
POST   /api/brackets/{id}/set-winner             # Establecer ganador manual
POST   /api/phases/{id}/create-next-round        # Crear siguiente ronda

# Fixture
POST   /api/tournaments/{id}/generate-fixture    # Generar fixture Round Robin
```

## Sistema de Liguilla

### Configuración al generar liguilla:
```json
{
  "liguilla_teams": 4|8|16,
  "include_copa": true|false,
  "copa_teams": 4|8|16,
  "format": "single_match|two_legs",
  "final_format": "single_match|two_legs",
  "tiebreaker": "global_score|away_goals|extra_time|higher_seed",
  "higher_seed_home_second": true|false,
  "start_date": "2024-03-01",
  "days_between_legs": 4,
  "days_between_rounds": 7,
  "match_times": { "cuartos": "20:00", "semifinal": "20:00", "final": "18:00" }
}
```

### Estructura de brackets:
- `round_number`: 1=Cuartos, 2=Semis, 3=Final (según cantidad de equipos)
- `bracket_position`: Posición en la ronda
- `home_seed`, `away_seed`: Posición original en tabla
- `is_two_legs`: Si es ida/vuelta
- `next_bracket_id`: Bracket de siguiente ronda

## Sistema de Horarios Flexibles

### Tipos de schedule:
- `single_day`: Un día con múltiples horarios (ej: Sábado 10:00, 12:00, 14:00)
- `multi_day`: Varios días de la semana (ej: Lunes-Viernes 20:00 y 21:30)

## Validaciones Importantes

**Jugador:** `position`: Portero, Defensa, Medio, Delantero

**Torneo:**
- `type`: Futbol 7, Futbol 9, Futbol 11
- `status`: Planificado, En Curso, Finalizado, Cancelado

**Partido:** `status`: Programado, En Vivo, Finalizado, Suspendido, Cancelado

**Evento:** `event_type`: Gol, Asistencia, Tarjeta Amarilla, Tarjeta Roja, Sustitución, Autogol

## Notas de Desarrollo

1. **Modelo Matchs**: Nombre es `Matchs` (no `Match`) porque `match` es palabra reservada en PHP 8.

2. **Timezone**: Configurado en `config/app.php` como `America/Mexico_City`.

3. **Top Scorers**: El endpoint `/export/standings` incluye `top_scorers` con goleadores del torneo.

4. **Storage**: Fotos en `storage/app/public/`. Crear symlink con `php artisan storage:link`.

5. **Usuario de prueba**: `admin@torneo.com` / `password123`
