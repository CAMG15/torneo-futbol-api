<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'lastname' => 'sometimes|string|max:255',
            'nickname' => 'nullable|string|max:255',
            'birthdate' => 'sometimes|date|before:today',
            'position' => 'sometimes|in:Portero,Defensa,Medio,Delantero',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'photo' => 'nullable|image|max:2048',
            'active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'birthdate.before' => 'La fecha de nacimiento debe ser anterior a hoy',
            'position.in' => 'La posición debe ser: Portero, Defensa, Medio o Delantero',
            'email.email' => 'El correo electrónico no es válido',
            'photo.image' => 'El archivo debe ser una imagen',
            'photo.max' => 'La imagen no debe superar 2MB',
        ];
    }
}
