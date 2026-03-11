<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return response()->json(Plan::ordered()->get());
    }

    public function update(Request $request, Plan $plan)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'description' => 'sometimes|nullable|string|max:500',
            'price_cents' => 'sometimes|integer|min:0',
            'is_active'   => 'sometimes|boolean',
            'features'    => 'sometimes|array',
            'features.public_site'       => 'sometimes|boolean',
            'features.export'            => 'sometimes|boolean',
            'features.custom_branding'   => 'sometimes|boolean',
            'features.custom_domain'     => 'sometimes|boolean',
            'features.sponsors'          => 'sometimes|boolean',
            'features.advanced_stats'    => 'sometimes|boolean',
            'features.auto_fixture'      => 'sometimes|boolean',
            'features.micopa_branding'   => 'sometimes|boolean',
            'limits'      => 'sometimes|array',
            'limits.max_tournaments'         => 'sometimes|integer|min:-1',
            'limits.max_teams_per_tournament' => 'sometimes|integer|min:-1',
        ]);

        // Merge features/limits parcialmente si vienen en la request
        if (isset($validated['features'])) {
            $validated['features'] = array_merge($plan->features ?? [], $validated['features']);
        }
        if (isset($validated['limits'])) {
            $validated['limits'] = array_merge($plan->limits ?? [], $validated['limits']);
        }

        $plan->update($validated);

        return response()->json($plan->fresh());
    }
}
