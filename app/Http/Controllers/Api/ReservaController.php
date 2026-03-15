<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cancha;
use App\Models\CanchaTarifa;
use App\Models\Matchs;
use App\Models\Reserva;
use App\Models\ReservaRecurrencia;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservaController extends Controller
{
    // ─── USO DEL PLAN ────────────────────────────────────────────────────────────

    public function usage(): JsonResponse
    {
        $tenant = app('current_tenant');

        if (!$tenant->isFreePlan()) {
            return response()->json([
                'plan'       => $tenant->plan,
                'is_limited' => false,
                'count'      => null,
                'limit'      => null,
            ]);
        }

        $count = $tenant->reservasMesActual();
        return response()->json([
            'plan'       => $tenant->plan,
            'is_limited' => true,
            'count'      => $count,
            'limit'      => 10,
        ]);
    }

    // ─── LISTADO ────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        if (!$tenantId) {
            return response()->json(['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1]);
        }

        $query = Reserva::with('cancha')
            ->where('tenant_id', $tenantId)
            ->orderBy('fecha', 'desc')
            ->orderBy('hora_inicio');

        if ($request->cancha_id) {
            $query->where('cancha_id', $request->cancha_id);
        }
        if ($request->estado) {
            $query->where('estado', $request->estado);
        }
        if ($request->tipo_evento) {
            $query->where('tipo_evento', $request->tipo_evento);
        }
        if ($request->desde) {
            $query->where('fecha', '>=', $request->desde);
        }
        if ($request->hasta) {
            $query->where('fecha', '<=', $request->hasta);
        }

        return response()->json($query->paginate(30));
    }

    // ─── CALENDARIO (formato FullCalendar) ──────────────────────────────────────

    public function calendar(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        if (!$tenantId) {
            return response()->json([]);
        }

        $desde = $request->get('desde', now()->startOfWeek()->toDateString());
        $hasta = $request->get('hasta', now()->endOfWeek()->toDateString());

        $query = Reserva::with('cancha')
            ->where('tenant_id', $tenantId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', '!=', 'cancelada');

        if ($request->cancha_id) {
            $query->where('cancha_id', $request->cancha_id);
        }

        $reservas  = $query->get();
        $partidos  = Matchs::with(['homeTeam', 'awayTeam', 'matchday.tournament'])
            ->where('tenant_id', $tenantId)
            ->whereBetween(DB::raw('DATE(match_date)'), [$desde, $hasta])
            ->whereNotNull('match_date')
            ->get();

        $events = [];

        foreach ($reservas as $r) {
            $events[] = [
                'id'              => 'r_' . $r->id,
                'title'           => $this->buildTitle($r),
                'start'           => $r->fecha->toDateString() . 'T' . $r->hora_inicio,
                'end'             => $r->fecha->toDateString() . 'T' . $r->hora_fin,
                'backgroundColor' => $this->colorByEstado($r->estado, $r->tipo_evento),
                'borderColor'     => $this->colorByEstado($r->estado, $r->tipo_evento),
                'extendedProps'   => [
                    'type'        => 'reserva',
                    'id'          => $r->id,
                    'estado'      => $r->estado,
                    'tipo_evento' => $r->tipo_evento,
                    'cancha'      => $r->cancha?->nombre,
                    'telefono'    => $r->telefono,
                ],
            ];
        }

        foreach ($partidos as $m) {
            $start = Carbon::parse($m->match_date);
            $end   = $start->copy()->addMinutes(90);
            $events[] = [
                'id'              => 'm_' . $m->id,
                'title'           => ($m->homeTeam?->name ?? '?') . ' vs ' . ($m->awayTeam?->name ?? '?'),
                'start'           => $start->toIso8601String(),
                'end'             => $end->toIso8601String(),
                'backgroundColor' => '#94a3b8',
                'borderColor'     => '#94a3b8',
                'textColor'       => '#1e293b',
                'extendedProps'   => [
                    'type'       => 'partido_torneo',
                    'match_id'   => $m->id,
                    'home_team'  => $m->homeTeam?->name ?? '?',
                    'away_team'  => $m->awayTeam?->name ?? '?',
                    'home_score' => $m->home_score,
                    'away_score' => $m->away_score,
                    'status'     => $m->status,
                    'location'   => $m->location,
                    'tournament' => $m->matchday?->tournament?->name,
                    'matchday'   => $m->matchday?->name ?? ($m->matchday ? 'Jornada ' . $m->matchday->number : null),
                ],
            ];
        }

        return response()->json($events);
    }

    // ─── CALCULAR PRECIO SUGERIDO ────────────────────────────────────────────────

    public function calcularPrecio(Request $request): JsonResponse
    {
        $request->validate([
            'cancha_id'   => 'required|exists:canchas,id',
            'fecha'       => 'required|date',
            'hora_inicio' => 'required|date_format:H:i',
            'hora_fin'    => 'required|date_format:H:i|after:hora_inicio',
        ]);

        $fecha      = Carbon::parse($request->fecha);
        $diaSemana  = $fecha->dayOfWeek; // 0=domingo, 6=sábado
        $esFinde    = in_array($diaSemana, [0, 6]);
        $diaTipo    = $esFinde ? 'finde' : 'semana';

        $tarifa = CanchaTarifa::where('cancha_id', $request->cancha_id)
            ->where('activa', true)
            ->where(function ($q) use ($diaTipo) {
                $q->where('dia_tipo', $diaTipo)->orWhere('dia_tipo', 'todos');
            })
            ->where('hora_desde', '<=', $request->hora_inicio)
            ->where('hora_hasta', '>=', $request->hora_fin)
            ->orderByRaw("CASE dia_tipo WHEN 'todos' THEN 1 ELSE 0 END") // priorizar específica
            ->first();

        if (!$tarifa) {
            return response()->json(['precio_sugerido' => null, 'tarifa_aplicada' => null]);
        }

        [$hIni, $mIni] = explode(':', $request->hora_inicio);
        [$hFin, $mFin] = explode(':', $request->hora_fin);
        $duracionHoras = (($hFin * 60 + $mFin) - ($hIni * 60 + $mIni)) / 60;
        $precioTotal   = round($tarifa->precio_hora * $duracionHoras, 2);

        return response()->json([
            'precio_sugerido' => $precioTotal,
            'tarifa_aplicada' => $tarifa,
        ]);
    }

    // ─── CRUD ────────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateReserva($request);

        $conflicto = $this->detectarConflicto(
            $validated['cancha_id'],
            $validated['fecha'],
            $validated['hora_inicio'],
            $validated['hora_fin']
        );

        if ($conflicto) {
            return response()->json([
                'error'     => 'El horario ya está ocupado. ' . $conflicto['mensaje'],
                'tipo'      => $conflicto['tipo'],
            ], 409);
        }

        $validated['created_by'] = $request->user()?->id;
        $reserva = Reserva::create($validated);

        return response()->json([
            'message' => 'Reserva creada exitosamente',
            'reserva' => $reserva->load('cancha'),
        ], 201);
    }

    public function show(Reserva $reserva): JsonResponse
    {
        return response()->json($reserva->load('cancha', 'recurrencia'));
    }

    public function update(Request $request, Reserva $reserva): JsonResponse
    {
        $validated = $this->validateReserva($request, $reserva->id);

        $conflicto = $this->detectarConflicto(
            $validated['cancha_id'],
            $validated['fecha'],
            $validated['hora_inicio'],
            $validated['hora_fin'],
            $reserva->id
        );

        if ($conflicto) {
            return response()->json([
                'error'     => 'El horario ya está ocupado. ' . $conflicto['mensaje'],
                'tipo'      => $conflicto['tipo'],
            ], 409);
        }

        $reserva->update($validated);

        return response()->json(['message' => 'Reserva actualizada', 'reserva' => $reserva->fresh('cancha')]);
    }

    public function destroy(Reserva $reserva): JsonResponse
    {
        $reserva->delete();
        return response()->json(['message' => 'Reserva eliminada']);
    }

    // ─── RECURRENCIAS (TURNO FIJO) ───────────────────────────────────────────────

    public function recurrencias(): JsonResponse
    {
        $recs = ReservaRecurrencia::with('cancha')
            ->where('activa', true)
            ->orderBy('dia_semana')
            ->orderBy('hora_inicio')
            ->get();

        return response()->json($recs);
    }

    public function storeRecurrencia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cancha_id'      => 'required|exists:canchas,id',
            'dia_semana'     => 'required|integer|between:0,6',
            'hora_inicio'    => 'required|date_format:H:i',
            'hora_fin'       => 'required|date_format:H:i|after:hora_inicio',
            'tipo_evento'    => 'required|in:partido_amistoso,entrenamiento,evento_privado,cumpleanos,mantenimiento,otro',
            'nombre_cliente' => 'nullable|string|max:100',
            'telefono'       => 'nullable|string|max:20',
            'nombre_equipo'  => 'nullable|string|max:100',
            'monto_total'    => 'nullable|numeric|min:0',
            'metodo_pago'    => 'nullable|in:efectivo,transferencia,mercadopago,otro',
            'fecha_inicio'   => 'required|date',
            'fecha_fin'      => 'nullable|date|after:fecha_inicio',
        ]);

        $recurrencia = ReservaRecurrencia::create($validated);

        // Generar reservas individuales automáticamente
        $fechaFin = $validated['fecha_fin']
            ? Carbon::parse($validated['fecha_fin'])
            : Carbon::parse($validated['fecha_inicio'])->addMonths(3);

        $fecha = Carbon::parse($validated['fecha_inicio']);
        // Avanzar hasta el próximo día de semana correcto
        while ($fecha->dayOfWeek !== (int) $validated['dia_semana']) {
            $fecha->addDay();
        }

        $creadas = 0;
        while ($fecha->lte($fechaFin)) {
            Reserva::create([
                'cancha_id'      => $validated['cancha_id'],
                'fecha'          => $fecha->toDateString(),
                'hora_inicio'    => $validated['hora_inicio'],
                'hora_fin'       => $validated['hora_fin'],
                'tipo_evento'    => $validated['tipo_evento'],
                'estado'         => 'confirmada',
                'nombre_cliente' => $validated['nombre_cliente'] ?? null,
                'telefono'       => $validated['telefono'] ?? null,
                'nombre_equipo'  => $validated['nombre_equipo'] ?? null,
                'monto_total'    => $validated['monto_total'] ?? null,
                'metodo_pago'    => $validated['metodo_pago'] ?? null,
                'recurrencia_id' => $recurrencia->id,
            ]);
            $fecha->addWeek();
            $creadas++;
        }

        return response()->json([
            'message'     => "Turno fijo creado. Se generaron {$creadas} reservas.",
            'recurrencia' => $recurrencia,
        ], 201);
    }

    public function updateRecurrencia(Request $request, ReservaRecurrencia $rec): JsonResponse
    {
        $validated = $request->validate([
            'nombre_cliente' => 'nullable|string|max:100',
            'telefono'       => 'nullable|string|max:20',
            'nombre_equipo'  => 'nullable|string|max:100',
            'monto_total'    => 'nullable|numeric|min:0',
            'metodo_pago'    => 'nullable|in:efectivo,transferencia,mercadopago,otro',
            'fecha_fin'      => 'nullable|date',
            'activa'         => 'sometimes|boolean',
        ]);

        $rec->update($validated);

        return response()->json(['message' => 'Recurrencia actualizada', 'recurrencia' => $rec->fresh()]);
    }

    public function destroyRecurrencia(ReservaRecurrencia $rec): JsonResponse
    {
        // Cancelar las reservas futuras pendientes de este turno fijo
        Reserva::where('recurrencia_id', $rec->id)
            ->where('fecha', '>=', now()->toDateString())
            ->where('estado', 'confirmada')
            ->update(['estado' => 'cancelada']);

        $rec->update(['activa' => false]);

        return response()->json(['message' => 'Turno fijo cancelado. Las reservas futuras han sido canceladas.']);
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────────

    private function validateReserva(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'cancha_id'          => 'required|exists:canchas,id',
            'fecha'              => 'required|date',
            'hora_inicio'        => 'required|date_format:H:i',
            'hora_fin'           => 'required|date_format:H:i|after:hora_inicio',
            'tipo_evento'        => 'required|in:partido_amistoso,entrenamiento,evento_privado,cumpleanos,mantenimiento,otro',
            'estado'             => 'sometimes|in:pendiente,confirmada,cancelada,completada,no_show',
            'nombre_cliente'     => 'nullable|string|max:100',
            'telefono'           => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:150',
            'nombre_equipo'      => 'nullable|string|max:100',
            'cantidad_jugadores' => 'nullable|integer|min:1|max:50',
            'monto_total'        => 'nullable|numeric|min:0',
            'monto_sena'         => 'nullable|numeric|min:0',
            'estado_pago'        => 'sometimes|in:sin_pagar,sena_pagada,pagado_total',
            'metodo_pago'        => 'nullable|in:efectivo,transferencia,mercadopago,otro',
            'notas_cliente'      => 'nullable|string|max:1000',
            'notas_internas'     => 'nullable|string|max:1000',
        ]);
    }

    private function detectarConflicto(
        int $canchaId,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?int $excludeId = null
    ): ?array {
        // 1. Conflicto con otras reservas en la misma cancha
        $reserva = Reserva::where('cancha_id', $canchaId)
            ->where('fecha', $fecha)
            ->where('estado', '!=', 'cancelada')
            ->where(function ($q) use ($horaInicio, $horaFin) {
                $q->where('hora_inicio', '<', $horaFin)
                  ->where('hora_fin', '>', $horaInicio);
            })
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->first();

        if ($reserva) {
            return [
                'tipo'    => 'reserva',
                'mensaje' => "Ya existe una reserva de {$reserva->hora_inicio} a {$reserva->hora_fin}.",
            ];
        }

        // 2. Conflicto con partidos de torneos del mismo tenant
        // Si el partido tiene cancha_id asignada, solo conflicto con la misma cancha.
        // Si no tiene cancha_id, aplica a todas (comportamiento anterior).
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;
        if ($tenantId) {
            $partido = Matchs::with(['homeTeam', 'awayTeam', 'matchday.tournament'])
                ->where('tenant_id', $tenantId)
                ->whereNotNull('match_date')
                ->whereNotIn('status', ['Cancelado', 'Suspendido'])
                ->where(function ($q) use ($canchaId) {
                    $q->whereNull('cancha_id')->orWhere('cancha_id', $canchaId);
                })
                ->whereRaw("DATE(CONVERT_TZ(match_date, '+00:00', '-06:00')) = ?", [$fecha])
                ->whereRaw("TIME(CONVERT_TZ(match_date, '+00:00', '-06:00')) < ?", [$horaFin])
                ->whereRaw("ADDTIME(TIME(CONVERT_TZ(match_date, '+00:00', '-06:00')), '01:30:00') > ?", [$horaInicio])
                ->first();

            if ($partido) {
                $home    = $partido->homeTeam?->name ?? '?';
                $away    = $partido->awayTeam?->name ?? '?';
                $torneo  = $partido->matchday?->tournament?->name ?? 'torneo';
                $hora    = Carbon::parse($partido->match_date)->setTimezone('America/Mexico_City')->format('H:i');
                return [
                    'tipo'    => 'partido_torneo',
                    'mensaje' => "Partido de torneo ({$torneo}): {$home} vs {$away} a las {$hora}.",
                ];
            }
        }

        return null;
    }

    private function buildTitle(Reserva $r): string
    {
        if ($r->tipo_evento === 'mantenimiento') {
            return '🔧 Mantenimiento';
        }
        $labels = [
            'partido_amistoso' => 'Amistoso',
            'entrenamiento'    => 'Entrenamiento',
            'evento_privado'   => 'Evento privado',
            'cumpleanos'       => 'Cumpleaños',
            'otro'             => 'Reserva',
        ];
        $tipo  = $labels[$r->tipo_evento] ?? 'Reserva';
        $quien = $r->nombre_equipo ?: $r->nombre_cliente;
        return $quien ? "{$quien} — {$tipo}" : $tipo;
    }

    private function colorByEstado(string $estado, string $tipo): string
    {
        if ($tipo === 'mantenimiento') return '#fca5a5'; // rojo claro
        return match ($estado) {
            'pendiente'   => '#fbbf24', // amarillo
            'confirmada'  => '#1e40af', // azul
            'completada'  => '#16a34a', // verde
            'no_show'     => '#dc2626', // rojo
            default       => '#94a3b8', // gris
        };
    }
}
