<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\Affiliate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $accountType = $request->input('account_type', 'admin');

        if ($accountType === 'affiliate') {
            $validated = $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            // Crear usuario
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Generar código único de 8 chars
            do {
                $code = strtoupper(Str::random(8));
            } while (Affiliate::where('code', $code)->exists());

            // Crear registro de afiliado
            $affiliate = Affiliate::create([
                'user_id'         => $user->id,
                'code'            => $code,
                'status'          => 'pending',
                'commission_rate' => 0.10,
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message'    => 'Registro exitoso',
                'user'       => $user,
                'tenant'     => null,
                'affiliate'  => [
                    'id'     => $affiliate->id,
                    'code'   => $affiliate->code,
                    'status' => $affiliate->status,
                ],
                'token'      => $token,
                'token_type' => 'Bearer',
            ], 201);
        }

        // Default: admin account type
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'email'         => 'required|string|email|max:255|unique:users',
            'password'      => 'required|string|min:8|confirmed',
            'cancha_name'   => 'required|string|max:255',
            'city'          => 'nullable|string|max:255',
            'referral_code' => 'nullable|string|max:10',
        ]);

        // Generar slug único
        $slug = Str::slug($validated['cancha_name']);
        $originalSlug = $slug;
        $counter = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        // Crear usuario
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Resolve referral code: verify it belongs to an active affiliate
        $referralCode = null;
        if (!empty($validated['referral_code'])) {
            $affiliateExists = Affiliate::where('code', strtoupper($validated['referral_code']))
                ->where('status', 'active')
                ->exists();
            if ($affiliateExists) {
                $referralCode = strtoupper($validated['referral_code']);
            }
        }

        // Crear tenant
        $tenant = Tenant::create([
            'name'             => $validated['cancha_name'],
            'slug'             => $slug,
            'owner_id'         => $user->id,
            'plan'             => 'free',
            'city'             => $validated['city'] ?? null,
            'email'            => $validated['email'],
            'is_active'        => true,
            'referred_by_code' => $referralCode,
        ]);

        // Asociar usuario como owner
        $tenant->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_tenant_id' => $tenant->id]);

        $token = $user->createToken('auth_token')->plainTextToken;

        // Enviar email de bienvenida (sin bloquear la respuesta)
        try {
            Mail::to($user->email)->queue(new WelcomeMail($user, $tenant));
        } catch (\Exception $e) {
            // No interrumpir el registro si el email falla
        }

        return response()->json([
            'message'    => 'Registro exitoso',
            'user'       => $user->load('currentTenant'),
            'tenant'     => $tenant,
            'affiliate'  => null,
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        // Cargar tenants del usuario
        $user->load(['tenants', 'currentTenant']);

        // Si no tiene tenant actual pero tiene tenants, asignar el primero
        if (!$user->current_tenant_id && $user->tenants->count() > 0) {
            $user->update(['current_tenant_id' => $user->tenants->first()->id]);
            $user->load('currentTenant');
        }

        $userData = $user->toArray();
        $userData['role_in_current_tenant'] = $user->currentTenant
            ? $user->roleInTenant($user->currentTenant)
            : null;

        $affiliate = Affiliate::where('user_id', $user->id)->first();

        return response()->json([
            'message'    => 'Inicio de sesión exitoso',
            'user'       => $userData,
            'tenant'     => $user->currentTenant,
            'tenants'    => $user->tenants,
            'token'      => $token,
            'token_type' => 'Bearer',
            'affiliate'  => $affiliate ? [
                'id'     => $affiliate->id,
                'status' => $affiliate->status,
                'code'   => $affiliate->code,
            ] : null,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada exitosamente'
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user()->load(['tenants', 'currentTenant']);

        $userData = $user->toArray();
        $userData['role_in_current_tenant'] = $user->currentTenant
            ? $user->roleInTenant($user->currentTenant)
            : null;

        return response()->json([
            'user' => $userData,
            'tenant' => $user->currentTenant,
            'tenants' => $user->tenants,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        Password::broker()->sendResetLink(
            $request->only('email')
        );

        // Siempre respondemos 200 para no revelar si el email existe
        return response()->json([
            'message' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:8|confirmed',
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // Revocar todos los tokens Sanctum existentes
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'El enlace de recuperación es inválido o ha expirado.',
            ], 422);
        }

        return response()->json([
            'message' => 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.',
        ]);
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual es incorrecta.',
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }
}
