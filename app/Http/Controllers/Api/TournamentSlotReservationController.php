<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentSlotReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentSlotReservationController extends Controller
{
    public function index(Tournament $tournament): JsonResponse
    {
        $reservations = TournamentSlotReservation::with(['team', 'cancha'])
            ->where('tournament_id', $tournament->id)
            ->orderBy('preferred_time')
            ->get();

        return response()->json($reservations);
    }

    public function store(Request $request, Tournament $tournament): JsonResponse
    {
        $validated = $request->validate([
            'team_id'               => 'required|integer|exists:teams,id',
            'preferred_time'        => 'required|date_format:H:i',
            'preferred_day_of_week' => 'nullable|integer|min:0|max:6',
            'cancha_id'             => 'nullable|integer|exists:canchas,id',
            'monto'                 => 'nullable|numeric|min:0',
            'estado_pago'           => 'sometimes|in:pendiente,pagado,vencido',
            'metodo_pago'           => 'nullable|string|max:100',
            'notas'                 => 'nullable|string|max:1000',
        ]);

        // Verificar que el equipo pertenece al torneo
        if (!$tournament->teams()->where('teams.id', $validated['team_id'])->exists()) {
            return response()->json(['message' => 'El equipo no pertenece a este torneo'], 422);
        }

        $reservation = TournamentSlotReservation::create(array_merge($validated, [
            'tenant_id'     => $tournament->tenant_id,
            'tournament_id' => $tournament->id,
        ]));

        return response()->json([
            'message'     => 'Horario fijo registrado',
            'reservation' => $reservation->load(['team', 'cancha']),
        ], 201);
    }

    public function update(Request $request, TournamentSlotReservation $slotReservation): JsonResponse
    {
        $validated = $request->validate([
            'preferred_time'        => 'sometimes|date_format:H:i',
            'preferred_day_of_week' => 'nullable|integer|min:0|max:6',
            'cancha_id'             => 'nullable|integer|exists:canchas,id',
            'monto'                 => 'nullable|numeric|min:0',
            'estado_pago'           => 'sometimes|in:pendiente,pagado,vencido',
            'metodo_pago'           => 'nullable|string|max:100',
            'notas'                 => 'nullable|string|max:1000',
        ]);

        $slotReservation->update($validated);

        return response()->json([
            'message'     => 'Horario fijo actualizado',
            'reservation' => $slotReservation->fresh(['team', 'cancha']),
        ]);
    }

    public function markPaid(Request $request, TournamentSlotReservation $slotReservation): JsonResponse
    {
        $validated = $request->validate([
            'metodo_pago' => 'nullable|string|max:100',
            'notas'       => 'nullable|string|max:1000',
        ]);

        $slotReservation->update(array_merge($validated, ['estado_pago' => 'pagado']));

        return response()->json([
            'message'     => 'Pago registrado',
            'reservation' => $slotReservation->fresh(['team', 'cancha']),
        ]);
    }

    public function destroy(TournamentSlotReservation $slotReservation): JsonResponse
    {
        $slotReservation->delete();
        return response()->json(['message' => 'Horario fijo eliminado']);
    }
}
