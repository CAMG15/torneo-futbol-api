<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'sometimes|in:Futbol 7,Futbol 9,Futbol 11',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => 'sometimes|in:Planificado,En Curso,Finalizado,Cancelado',
            'banner' => 'nullable|image|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'El tipo debe ser: Futbol 7, Futbol 9 o Futbol 11',
            'end_date.after' => 'La fecha de fin debe ser posterior a la fecha de inicio',
            'status.in' => 'El estado debe ser: Planificado, En Curso, Finalizado o Cancelado',
            'banner.image' => 'El banner debe ser una imagen',
            'banner.max' => 'El banner no debe superar 2MB',
        ];
    }
}
