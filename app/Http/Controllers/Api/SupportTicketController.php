<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupportTicketController extends Controller
{
    /**
     * Listado de tickets del tenant actual (admin)
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        $tickets = SupportTicket::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($t) => $this->formatTicket($t));

        return response()->json($tickets);
    }

    /**
     * Crear nuevo ticket (admin)
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        $validated = $request->validate([
            'title'          => 'required|string|max:255',
            'description'    => 'required|string|max:5000',
            'category'       => 'required|in:bug,mejora,pregunta,otro',
            'priority'       => 'required|in:baja,media,alta,urgente',
            'screenshots'    => 'nullable|array|max:3',
            'screenshots.*'  => 'image|max:3072',
        ]);

        $screenshotPaths = [];
        if ($request->hasFile('screenshots')) {
            foreach ($request->file('screenshots') as $file) {
                $screenshotPaths[] = $file->store('support-screenshots', 'public');
            }
        }

        $ticket = SupportTicket::create([
            'tenant_id'   => $tenantId,
            'user_id'     => $request->user()->id,
            'title'       => $validated['title'],
            'description' => $validated['description'],
            'category'    => $validated['category'],
            'priority'    => $validated['priority'],
            'screenshots' => $screenshotPaths ?: null,
            'status'      => 'abierto',
        ]);

        return response()->json($this->formatTicket($ticket), 201);
    }

    /**
     * Detalle de ticket del tenant actual (admin)
     */
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        if ($ticket->tenant_id !== $tenantId) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        return response()->json($this->formatTicket($ticket));
    }

    /**
     * Listado de todos los tickets (superadmin)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = SupportTicket::with(['tenant', 'user'])
            ->orderBy('created_at', 'desc');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->plan) {
            $query->whereHas('tenant', fn ($q) => $q->where('plan', $request->plan));
        }

        $tickets = $query->paginate(20);

        $tickets->getCollection()->transform(fn ($t) => $this->formatTicketAdmin($t));

        return response()->json($tickets);
    }

    /**
     * Actualizar ticket (superadmin): cambiar estado y/o responder
     */
    public function adminUpdate(Request $request, SupportTicket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'status'      => 'sometimes|in:abierto,en_revision,resuelto,cerrado',
            'admin_notes' => 'sometimes|nullable|string|max:5000',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'resuelto' && !$ticket->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $ticket->update($validated);

        return response()->json($this->formatTicketAdmin($ticket->fresh(['tenant', 'user'])));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatTicket(SupportTicket $ticket): array
    {
        return [
            'id'          => $ticket->id,
            'title'       => $ticket->title,
            'description' => $ticket->description,
            'category'    => $ticket->category,
            'priority'    => $ticket->priority,
            'status'      => $ticket->status,
            'screenshots' => collect($ticket->screenshots ?? [])->map(
                fn ($p) => Storage::disk('public')->url($p)
            ),
            'admin_notes' => $ticket->admin_notes,
            'resolved_at' => $ticket->resolved_at,
            'created_at'  => $ticket->created_at,
        ];
    }

    private function formatTicketAdmin(SupportTicket $ticket): array
    {
        return array_merge($this->formatTicket($ticket), [
            'tenant' => [
                'id'   => $ticket->tenant?->id,
                'name' => $ticket->tenant?->name,
                'plan' => $ticket->tenant?->plan,
                'slug' => $ticket->tenant?->slug,
            ],
            'user' => [
                'id'   => $ticket->user?->id,
                'name' => $ticket->user?->name,
                'email'=> $ticket->user?->email,
            ],
        ]);
    }
}
