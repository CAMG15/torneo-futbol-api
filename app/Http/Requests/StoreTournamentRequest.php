<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:Futbol 7,Futbol 9,Futbol 11',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'banner' => 'nullable|image|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del torneo es obligatorio',
            'type.required' => 'El tipo de torneo es obligatorio',
            'type.in' => 'El tipo debe ser: Futbol 7, Futbol 9 o Futbol 11',
            'start_date.required' => 'La fecha de inicio es obligatoria',
            'end_date.after' => 'La fecha de fin debe ser posterior a la fecha de inicio',
            'banner.image' => 'El banner debe ser una imagen',
            'banner.max' => 'El banner no debe superar 2MB',
        ];
    }
}
