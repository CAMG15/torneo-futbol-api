<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMatchEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_id' => 'required|exists:players,id',
            'team_id' => 'required|exists:teams,id',
            'event_type' => 'required|in:Gol,Asistencia,Tarjeta Amarilla,Tarjeta Roja,Sustitución,Autogol',
            'minute' => 'required|integer|min:0|max:120',
            'notes' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'player_id.required' => 'El jugador es obligatorio',
            'player_id.exists' => 'El jugador no existe',
            'team_id.required' => 'El equipo es obligatorio',
            'team_id.exists' => 'El equipo no existe',
            'event_type.required' => 'El tipo de evento es obligatorio',
            'event_type.in' => 'El tipo de evento no es válido',
            'minute.required' => 'El minuto es obligatorio',
            'minute.min' => 'El minuto no puede ser negativo',
            'minute.max' => 'El minuto no puede ser mayor a 120',
        ];
    }
}
