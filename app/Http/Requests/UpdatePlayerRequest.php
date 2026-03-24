<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Player;

class UpdatePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->any()) return;

            $player = $this->route('player');
            $tenantId = $this->user()?->tenant_id;

            // Usar valores actuales del jugador si no se envían en el request
            $name      = $this->name      ?? $player->name;
            $lastname  = $this->lastname  ?? $player->lastname;
            $birthdate = $this->birthdate ?? $player->birthdate;

            $exists = Player::where('tenant_id', $tenantId)
                ->where('name', $name)
                ->where('lastname', $lastname)
                ->where('birthdate', $birthdate)
                ->where('id', '!=', $player->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'Ya existe un jugador registrado con ese nombre, apellido y fecha de nacimiento.');
            }
        });
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
