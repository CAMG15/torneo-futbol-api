<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('teams', 'name')->where('tenant_id', $tenantId),
            ],
            'short_name' => 'nullable|string|max:10',
            'logo' => 'nullable|image|max:2048',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'captain_id' => 'nullable|exists:players,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del equipo es obligatorio',
            'name.unique' => 'Ya existe un equipo con este nombre',
            'short_name.max' => 'El nombre corto no debe superar 10 caracteres',
            'logo.image' => 'El logo debe ser una imagen',
            'logo.max' => 'El logo no debe superar 2MB',
            'captain_id.exists' => 'El capitán seleccionado no existe',
        ];
    }
}
