<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateFixtureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'rounds' => 'required|integer|min:1|max:3',
            'start_date' => 'required|date|after_or_equal:today',
            'days_between_matchdays' => 'required|integer|min:1|max:14',
            'location' => 'nullable|string|max:255',

            // Nuevo: tipo de schedule
            'schedule_type' => 'required|in:single_day,multi_day',
        ];

        // Reglas para single_day (un solo día por jornada)
        if ($this->input('schedule_type') === 'single_day') {
            $rules = array_merge($rules, [
                'match_time' => 'required_without:time_slots|date_format:H:i',
                'matches_per_day' => 'required_without:time_slots|integer|min:1|max:10',

                // Alternativa: slots de horarios personalizados
                'time_slots' => 'nullable|array|min:1',
                'time_slots.*.start_time' => 'required_with:time_slots|date_format:H:i',
                'time_slots.*.matches_count' => 'required_with:time_slots|integer|min:1|max:5',
                'time_slots.*.location' => 'nullable|string|max:255',
            ]);
        }

        // Reglas para multi_day (varios días por jornada)
        if ($this->input('schedule_type') === 'multi_day') {
            $rules = array_merge($rules, [
                'week_schedule' => 'required|array|min:1|max:7',
                'week_schedule.*.day_of_week' => 'required|integer|min:0|max:6',
                'week_schedule.*.time_slots' => 'required|array|min:1',
                'week_schedule.*.time_slots.*.start_time' => 'required|date_format:H:i',
                'week_schedule.*.time_slots.*.matches_count' => 'required|integer|min:1|max:5',
                'week_schedule.*.time_slots.*.location' => 'nullable|string|max:255',
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'rounds.required' => 'El numero de rondas es obligatorio',
            'rounds.min' => 'Debe haber al menos 1 ronda',
            'rounds.max' => 'No puede haber mas de 3 rondas',
            'start_date.required' => 'La fecha de inicio es obligatoria',
            'start_date.after_or_equal' => 'La fecha de inicio debe ser hoy o posterior',
            'match_time.required_without' => 'La hora de inicio es obligatoria si no hay slots',
            'match_time.date_format' => 'El formato de hora debe ser HH:MM',
            'days_between_matchdays.required' => 'Los dias entre jornadas son obligatorios',
            'days_between_matchdays.min' => 'Debe haber al menos 1 dia entre jornadas',
            'days_between_matchdays.max' => 'No puede haber mas de 14 dias entre jornadas',
            'matches_per_day.required_without' => 'Los partidos por dia son obligatorios si no hay slots',
            'matches_per_day.min' => 'Debe haber al menos 1 partido por dia',
            'matches_per_day.max' => 'No puede haber mas de 10 partidos por dia',
            'schedule_type.required' => 'El tipo de horario es obligatorio',
            'schedule_type.in' => 'El tipo de horario debe ser single_day o multi_day',
            'week_schedule.required' => 'El horario semanal es obligatorio para multi_day',
            'week_schedule.*.day_of_week.required' => 'El dia de la semana es obligatorio',
            'week_schedule.*.day_of_week.min' => 'El dia de la semana debe ser entre 0 (Domingo) y 6 (Sabado)',
            'week_schedule.*.day_of_week.max' => 'El dia de la semana debe ser entre 0 (Domingo) y 6 (Sabado)',
            'time_slots.*.start_time.required_with' => 'La hora de inicio del slot es obligatoria',
            'time_slots.*.matches_count.required_with' => 'El numero de partidos del slot es obligatorio',
        ];
    }

    /**
     * Preparar los datos para validacion
     */
    protected function prepareForValidation(): void
    {
        // Compatibilidad: si no se especifica schedule_type, usar single_day
        if (!$this->has('schedule_type')) {
            $this->merge(['schedule_type' => 'single_day']);
        }
    }
}
