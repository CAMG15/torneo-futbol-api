<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cancha;
use App\Models\CanchaTarifa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CanchaController extends Controller
{
    public function index(): JsonResponse
    {
        $canchas = Cancha::with('tarifas')->orderBy('nombre')->get();
        return response()->json($canchas);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:100',
            'tipo'        => 'required|in:futbol5,futbol7,futbol9,futbol11,futsal,otro',
            'descripcion' => 'nullable|string|max:500',
            'activa'      => 'sometimes|boolean',
        ]);

        $cancha = Cancha::create($validated);

        return response()->json(['message' => 'Cancha creada exitosamente', 'cancha' => $cancha], 201);
    }

    public function update(Request $request, Cancha $cancha): JsonResponse
    {
        $validated = $request->validate([
            'nombre'      => 'sometimes|string|max:100',
            'tipo'        => 'sometimes|in:futbol5,futbol7,futbol9,futbol11,futsal,otro',
            'descripcion' => 'nullable|string|max:500',
            'activa'      => 'sometimes|boolean',
        ]);

        $cancha->update($validated);

        return response()->json(['message' => 'Cancha actualizada', 'cancha' => $cancha->fresh('tarifas')]);
    }

    public function destroy(Cancha $cancha): JsonResponse
    {
        $cancha->delete();
        return response()->json(['message' => 'Cancha eliminada']);
    }

    // --- Tarifas ---

    public function tarifas(Cancha $cancha): JsonResponse
    {
        return response()->json($cancha->tarifas()->orderBy('dia_tipo')->orderBy('hora_desde')->get());
    }

    public function storeTarifa(Request $request, Cancha $cancha): JsonResponse
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:100',
            'dia_tipo'    => 'required|in:semana,finde,todos',
            'hora_desde'  => 'required|date_format:H:i',
            'hora_hasta'  => 'required|date_format:H:i|after:hora_desde',
            'precio_hora' => 'required|numeric|min:0',
            'moneda'      => 'sometimes|string|size:3',
            'activa'      => 'sometimes|boolean',
        ]);

        $tarifa = $cancha->tarifas()->create($validated);

        return response()->json(['message' => 'Tarifa creada', 'tarifa' => $tarifa], 201);
    }

    public function updateTarifa(Request $request, CanchaTarifa $tarifa): JsonResponse
    {
        $validated = $request->validate([
            'nombre'      => 'sometimes|string|max:100',
            'dia_tipo'    => 'sometimes|in:semana,finde,todos',
            'hora_desde'  => 'sometimes|date_format:H:i',
            'hora_hasta'  => 'sometimes|date_format:H:i',
            'precio_hora' => 'sometimes|numeric|min:0',
            'moneda'      => 'sometimes|string|size:3',
            'activa'      => 'sometimes|boolean',
        ]);

        $tarifa->update($validated);

        return response()->json(['message' => 'Tarifa actualizada', 'tarifa' => $tarifa->fresh()]);
    }

    public function destroyTarifa(CanchaTarifa $tarifa): JsonResponse
    {
        $tarifa->delete();
        return response()->json(['message' => 'Tarifa eliminada']);
    }
}
