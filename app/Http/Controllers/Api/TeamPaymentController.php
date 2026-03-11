<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeamPayment;
use App\Models\Team;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamPaymentController extends Controller
{
    // ─── RESUMEN ─────────────────────────────────────────────────────────────────

    public function summary(): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;
        if (!$tenantId) {
            return response()->json(['total_cobrado' => 0, 'total_pendiente' => 0, 'total_vencidos' => 0, 'count' => 0]);
        }

        $payments = TeamPayment::where('tenant_id', $tenantId)
            ->whereIn('estado', ['pendiente', 'parcial', 'pagado'])
            ->get();

        $totalCobrado   = $payments->where('estado', 'pagado')->sum('monto');
        $totalParcial   = $payments->where('estado', 'parcial')->sum('monto_pagado');
        $totalPendiente = $payments->whereIn('estado', ['pendiente', 'parcial'])
            ->sum(fn($p) => $p->monto - $p->monto_pagado);
        $totalVencidos  = $payments->filter(fn($p) => $p->es_vencido)->count();

        return response()->json([
            'total_cobrado'   => round($totalCobrado + $totalParcial, 2),
            'total_pendiente' => round($totalPendiente, 2),
            'total_vencidos'  => $totalVencidos,
            'count'           => $payments->count(),
        ]);
    }

    // ─── LISTADO ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;
        if (!$tenantId) {
            return response()->json(['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1]);
        }

        $query = TeamPayment::where('tenant_id', $tenantId)
            ->with(['team:id,name', 'tournament:id,name']);

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->team_id);
        }
        if ($request->filled('tournament_id')) {
            $query->where('tournament_id', $request->tournament_id);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('concepto')) {
            $query->where('concepto', $request->concepto);
        }
        if ($request->boolean('vencidos')) {
            $query->where('fecha_vencimiento', '<', now())
                  ->whereNotIn('estado', ['pagado', 'cancelado']);
        }

        $payments = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data'         => $payments->map(fn($p) => $this->formatPayment($p)),
            'total'        => $payments->total(),
            'current_page' => $payments->currentPage(),
            'last_page'    => $payments->lastPage(),
        ]);
    }

    // ─── CREAR ───────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');
        if (!$tenant) {
            return response()->json(['message' => 'Tenant no encontrado'], 404);
        }

        $validated = $request->validate([
            'team_id'           => 'nullable|exists:teams,id',
            'tournament_id'     => 'nullable|exists:tournaments,id',
            'concepto'          => 'required|in:inscripcion,arbitraje,multa,otro',
            'descripcion'       => 'nullable|string|max:255',
            'monto'             => 'required|numeric|min:0.01',
            'monto_pagado'      => 'nullable|numeric|min:0',
            'metodo_pago'       => 'nullable|in:efectivo,transferencia,mercadopago,otro',
            'fecha_vencimiento' => 'nullable|date',
            'notas'             => 'nullable|string',
        ]);

        $validated['tenant_id']  = $tenant->id;
        $validated['created_by'] = auth()->id();
        $validated['monto_pagado'] = $validated['monto_pagado'] ?? 0;

        $payment = TeamPayment::create($validated);
        $payment->load(['team:id,name', 'tournament:id,name']);

        return response()->json($this->formatPayment($payment), 201);
    }

    // ─── VER ─────────────────────────────────────────────────────────────────────

    public function show(TeamPayment $teamPayment): JsonResponse
    {
        $this->authorizeTenant($teamPayment);
        $teamPayment->load(['team:id,name', 'tournament:id,name', 'createdBy:id,name']);
        return response()->json($this->formatPayment($teamPayment));
    }

    // ─── ACTUALIZAR ──────────────────────────────────────────────────────────────

    public function update(Request $request, TeamPayment $teamPayment): JsonResponse
    {
        $this->authorizeTenant($teamPayment);

        $validated = $request->validate([
            'team_id'           => 'nullable|exists:teams,id',
            'tournament_id'     => 'nullable|exists:tournaments,id',
            'concepto'          => 'sometimes|in:inscripcion,arbitraje,multa,otro',
            'descripcion'       => 'nullable|string|max:255',
            'monto'             => 'sometimes|numeric|min:0.01',
            'monto_pagado'      => 'nullable|numeric|min:0',
            'metodo_pago'       => 'nullable|in:efectivo,transferencia,mercadopago,otro',
            'fecha_vencimiento' => 'nullable|date',
            'notas'             => 'nullable|string',
            'estado'            => 'sometimes|in:pendiente,parcial,pagado,cancelado',
        ]);

        $teamPayment->update($validated);
        $teamPayment->load(['team:id,name', 'tournament:id,name']);

        return response()->json($this->formatPayment($teamPayment));
    }

    // ─── MARCAR COMO PAGADO ───────────────────────────────────────────────────────

    public function markAsPaid(Request $request, TeamPayment $teamPayment): JsonResponse
    {
        $this->authorizeTenant($teamPayment);

        $validated = $request->validate([
            'metodo_pago' => 'nullable|in:efectivo,transferencia,mercadopago,otro',
        ]);

        $teamPayment->update([
            'monto_pagado' => $teamPayment->monto,
            'metodo_pago'  => $validated['metodo_pago'] ?? $teamPayment->metodo_pago,
            'estado'       => 'pagado',
            'pagado_at'    => now(),
        ]);

        $teamPayment->load(['team:id,name', 'tournament:id,name']);
        return response()->json($this->formatPayment($teamPayment));
    }

    // ─── ELIMINAR ────────────────────────────────────────────────────────────────

    public function destroy(TeamPayment $teamPayment): JsonResponse
    {
        $this->authorizeTenant($teamPayment);
        $teamPayment->delete();
        return response()->json(['message' => 'Pago eliminado correctamente']);
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────────

    private function authorizeTenant(TeamPayment $payment): void
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;
        abort_if($payment->tenant_id !== $tenantId, 403, 'No autorizado');
    }

    private function formatPayment(TeamPayment $p): array
    {
        return [
            'id'                => $p->id,
            'team_id'           => $p->team_id,
            'team_name'         => $p->team?->name,
            'tournament_id'     => $p->tournament_id,
            'tournament_name'   => $p->tournament?->name,
            'concepto'          => $p->concepto,
            'descripcion'       => $p->descripcion,
            'monto'             => (float) $p->monto,
            'monto_pagado'      => (float) $p->monto_pagado,
            'monto_pendiente'   => (float) $p->monto_pendiente,
            'estado'            => $p->estado,
            'metodo_pago'       => $p->metodo_pago,
            'fecha_vencimiento' => $p->fecha_vencimiento?->format('Y-m-d'),
            'pagado_at'         => $p->pagado_at?->toIso8601String(),
            'es_vencido'        => $p->es_vencido,
            'notas'             => $p->notas,
            'created_at'        => $p->created_at->toIso8601String(),
        ];
    }
}
