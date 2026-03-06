<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdvertisementController extends Controller
{
    /**
     * Obtener anuncios activos (para portal público)
     */
    public function active(Request $request)
    {
        $position = $request->query('position');
        
        $query = Advertisement::active()->ordered();
        
        if ($position) {
            $query->position($position);
        }

        $advertisements = $query->get();

        return response()->json($advertisements);
    }

    /**
     * Listar todos los anuncios (admin)
     */
    public function index()
    {
        $advertisements = Advertisement::orderBy('order')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($advertisements);
    }

    /**
     * Verificar que el tenant tiene plan pro o business
     */
    private function requireProPlan(): ?\Illuminate\Http\JsonResponse
    {
        $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;
        if (!$tenant || !in_array($tenant->plan, ['pro', 'business'])) {
            return response()->json([
                'error'            => 'Necesitas plan Pro o Business para gestionar anuncios.',
                'upgrade_required' => true,
            ], 403);
        }
        return null;
    }

    /**
     * Crear nuevo anuncio
     */
    public function store(Request $request)
    {
        if ($err = $this->requireProPlan()) return $err;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'image' => 'required|image|max:2048',
            'link' => 'nullable|url',
            'position' => 'required|in:Header,Sidebar,Footer,Popup',
            'order' => 'nullable|integer|min:0',
            'active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date'
        ]);

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('advertisements', 'public');
        }

        $advertisement = Advertisement::create($validated);

        return response()->json($advertisement, 201);
    }

    /**
     * Mostrar un anuncio
     */
    public function show(Advertisement $advertisement)
    {
        return response()->json($advertisement);
    }

    /**
     * Actualizar anuncio
     */
    public function update(Request $request, Advertisement $advertisement)
    {
        if ($err = $this->requireProPlan()) return $err;

        $validated = $request->validate([
            'title' => 'string|max:255',
            'image' => 'nullable|image|max:2048',
            'link' => 'nullable|url',
            'position' => 'in:Header,Sidebar,Footer,Popup',
            'order' => 'nullable|integer|min:0',
            'active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date'
        ]);

        if ($request->hasFile('image')) {
            // Eliminar imagen anterior
            if ($advertisement->image) {
                Storage::disk('public')->delete($advertisement->image);
            }
            $validated['image'] = $request->file('image')->store('advertisements', 'public');
        }

        $advertisement->update($validated);

        return response()->json($advertisement);
    }

    /**
     * Eliminar anuncio
     */
    public function destroy(Advertisement $advertisement)
    {
        if ($err = $this->requireProPlan()) return $err;

        // Eliminar imagen
        if ($advertisement->image) {
            Storage::disk('public')->delete($advertisement->image);
        }

        $advertisement->delete();

        return response()->json(['message' => 'Anuncio eliminado exitosamente']);
    }

    /**
     * Activar/desactivar anuncio
     */
    public function toggleActive(Advertisement $advertisement)
    {
        if ($err = $this->requireProPlan()) return $err;

        $advertisement->active = !$advertisement->active;
        $advertisement->save();

        return response()->json([
            'message' => $advertisement->active ? 'Anuncio activado' : 'Anuncio desactivado',
            'advertisement' => $advertisement
        ]);
    }

    /**
     * Reordenar anuncios
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'advertisements' => 'required|array',
            'advertisements.*.id' => 'required|exists:advertisements,id',
            'advertisements.*.order' => 'required|integer|min:0'
        ]);

        foreach ($validated['advertisements'] as $item) {
            Advertisement::where('id', $item['id'])
                ->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Anuncios reordenados exitosamente']);
    }

    /**
     * Obtener estadísticas de anuncios
     */
    public function statistics()
    {
        $stats = [
            'total' => Advertisement::count(),
            'active' => Advertisement::active()->count(),
            'inactive' => Advertisement::where('active', false)->count(),
            'expiring_soon' => Advertisement::active()
                ->whereNotNull('end_date')
                ->where('end_date', '<=', now()->addDays(7))
                ->count(),
            'by_position' => [
                'header' => Advertisement::active()->position('Header')->count(),
                'sidebar' => Advertisement::active()->position('Sidebar')->count(),
                'footer' => Advertisement::active()->position('Footer')->count(),
                'popup' => Advertisement::active()->position('Popup')->count()
            ]
        ];

        return response()->json($stats);
    }
}