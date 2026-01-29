<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'nickname' => 'nullable|string|max:255',
            'birthdate' => 'required|date|before:today',
            'position' => 'required|in:Portero,Defensa,Medio,Delantero',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'photo' => 'nullable|image|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio',
            'lastname.required' => 'El apellido es obligatorio',
            'birthdate.required' => 'La fecha de nacimiento es obligatoria',
            'birthdate.before' => 'La fecha de nacimiento debe ser anterior a hoy',
            'position.required' => 'La posición es obligatoria',
            'position.in' => 'La posición debe ser: Portero, Defensa, Medio o Delantero',
            'email.email' => 'El correo electrónico no es válido',
            'photo.image' => 'El archivo debe ser una imagen',
            'photo.max' => 'La imagen no debe superar 2MB',
        ];
    }
}
