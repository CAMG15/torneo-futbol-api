<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignExistingDataToTenant extends Command
{
    protected $signature = 'tenant:assign-existing-data';

    protected $description = 'Crea un tenant default y asigna todos los datos existentes a ese tenant';

    public function handle()
    {
        $this->info('Iniciando migración de datos a tenant...');

        // 1. Buscar o crear usuario owner
        $user = User::first();

        if (!$user) {
            $this->error('No hay usuarios en la base de datos. Crea uno primero.');
            return 1;
        }

        $this->info("Usando usuario: {$user->name} ({$user->email})");

        // 2. Crear tenant default
        $tenantName = $this->ask('Nombre de la cancha/tenant', 'Mi Cancha');

        $tenant = Tenant::create([
            'name' => $tenantName,
            'slug' => Str::slug($tenantName),
            'owner_id' => $user->id,
            'plan' => 'pro', // Pro para no limitar datos existentes
            'email' => $user->email,
            'is_active' => true,
        ]);

        $this->info("Tenant creado: {$tenant->name} (ID: {$tenant->id})");

        // 3. Asociar usuario al tenant
        $tenant->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_tenant_id' => $tenant->id]);

        // 4. Asignar tenant_id a tablas principales
        $tables = ['tournaments', 'teams', 'players', 'advertisements', 'matches'];

        foreach ($tables as $table) {
            $count = DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenant->id]);
            $this->info("  {$table}: {$count} registros actualizados");
        }

        // 5. Asociar todos los usuarios existentes al tenant
        $users = User::where('id', '!=', $user->id)->get();
        foreach ($users as $otherUser) {
            if (!$tenant->users()->where('user_id', $otherUser->id)->exists()) {
                $tenant->users()->attach($otherUser->id, ['role' => 'admin']);
                $otherUser->update(['current_tenant_id' => $tenant->id]);
            }
        }

        $this->info("  Usuarios asociados: " . ($users->count() + 1));

        $this->newLine();
        $this->info('Migración completada exitosamente.');
        $this->info("Tenant: {$tenant->name} | Slug: {$tenant->slug} | Plan: {$tenant->plan}");

        return 0;
    }
}
