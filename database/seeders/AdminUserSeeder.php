<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Seeder para crear el usuario administrador del sistema.
 *
 * Este seeder crea automáticamente un usuario con rol de administrador
 * usando credenciales definidas en las variables de entorno.
 *
 * Variables de entorno requeridas:
 * - ADMIN_EMAIL: Email del administrador (debe terminar en @segurosbolivar.com)
 * - ADMIN_PASSWORD: Contraseña del administrador
 *
 * Variables de entorno opcionales:
 * - ADMIN_NAME: Nombre completo (default: "Administrador del Sistema")
 * - ADMIN_POSITION: Cargo (default: "Administrador")
 *
 * El seeder es idempotente: si el usuario ya existe, lo actualiza.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        // Validar que las credenciales estén configuradas
        if (empty($email) || empty($password)) {
            $this->command->warn('⚠️  Variables ADMIN_EMAIL y ADMIN_PASSWORD no configuradas en .env');
            $this->command->warn('⚠️  Saltando creación de usuario administrador');
            return;
        }

        // Validar formato de email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->command->error('❌ ADMIN_EMAIL no es un email válido: ' . $email);
            return;
        }

        // Validar dominio del email
        if (!str_ends_with(strtolower($email), '@segurosbolivar.com')) {
            $this->command->error('❌ ADMIN_EMAIL debe terminar en @segurosbolivar.com');
            return;
        }

        // Obtener valores opcionales con defaults
        $name = env('ADMIN_NAME', 'Administrador del Sistema');
        $position = env('ADMIN_POSITION', 'Administrador');

        // Obtener el área ARL (ID: 1) - requerida para todos los usuarios
        $areaId = 1;

        $this->command->info('📝 Creando/actualizando usuario administrador...');

        // Crear o actualizar usuario administrador
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'position' => $position,
                'area_id' => $areaId,
                'supervisor_id' => null,
                'subdepartment_id' => null,
                'team_id' => null,
            ]
        );

        // Asignar rol de administrador (Spatie)
        $adminRole = Role::where('name', 'administrator')
            ->where('guard_name', 'web')
            ->first();

        if (!$adminRole) {
            $this->command->error('❌ Rol "administrator" no encontrado. Ejecuta RoleSeeder primero.');
            return;
        }

        // Sincronizar roles (remover roles previos y asignar solo administrator)
        $user->syncRoles([$adminRole]);

        $this->command->info('✅ Usuario administrador creado/actualizado exitosamente');
        $this->command->info('   📧 Email: ' . $user->email);
        $this->command->info('   👤 Nombre: ' . $user->name);
        $this->command->info('   💼 Cargo: ' . $user->position);
        $this->command->info('   🎭 Rol: administrator');
    }
}
