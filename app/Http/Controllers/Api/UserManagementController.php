<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    /**
     * GET /users — Lista usuarios del tenant actual con sus roles.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');

        $users = $tenant->users()->withPivot('role')->get()
            ->map(fn ($u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->pivot->role,
            ]);

        return response()->json($users);
    }

    /**
     * POST /users — Crea un nuevo usuario y lo asocia al tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app('current_tenant');
        $callerRole = $request->user()->roleInTenant($tenant);

        if (!in_array($callerRole, ['owner', 'admin']) && !$request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'No tienes permisos para gestionar usuarios'], 403);
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:admin,editor,arbitro',
        ]);

        $user = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => Hash::make($validated['password']),
            'current_tenant_id' => $tenant->id,
        ]);

        $tenant->users()->attach($user->id, ['role' => $validated['role']]);

        return response()->json([
            'message' => 'Usuario creado correctamente',
            'user'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $validated['role'],
            ],
        ], 201);
    }

    /**
     * DELETE /users/{user} — Remueve al usuario del tenant (no elimina la cuenta).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $tenant = app('current_tenant');
        $callerRole = $request->user()->roleInTenant($tenant);

        if (!in_array($callerRole, ['owner', 'admin']) && !$request->user()->isSuperAdmin()) {
            return response()->json(['error' => 'No tienes permisos para gestionar usuarios'], 403);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'No puedes eliminarte a ti mismo'], 422);
        }

        // Verificar que el usuario pertenece al tenant
        if (!$tenant->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'El usuario no pertenece a este tenant'], 404);
        }

        $tenant->users()->detach($user->id);

        return response()->json(['message' => 'Usuario removido del tenant correctamente']);
    }
}
