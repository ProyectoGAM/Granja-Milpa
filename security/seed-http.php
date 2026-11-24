<?php

declare(strict_types=1);

use App\Models\IdentityAndAccess\Role;
use App\Models\User;
use App\Models\SharedDevice;
use App\Models\FarmStructure\ProductionUnit;
use App\Services\IdentityAndAccess\PinHasher;
use Illuminate\Contracts\Console\Kernel;
use Spatie\Permission\Models\Permission;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.pgsql.database') !== 'gam_security'
    || config('app.url') !== 'http://web:8080'
    || env('DEMO_DB_RESET_ENABLED') !== false) {
    throw new RuntimeException('El fixture de seguridad requiere la base descartable.');
}

$role = Role::findOrCreate('security-fixture', 'web');
$role->givePermissionTo(Permission::findOrCreate('can_login_native', 'web'));
$user = User::query()->create([
    'name' => 'Security fixture',
    'email' => 'security-fixture@example.test',
    'password' => getenv('GAM_SECURITY_FIXTURE_PASSWORD'),
]);
$user->syncRoles([$role]);
$adminRole = Role::findOrCreate('admin', 'web');
foreach (['can_login_native', 'can_start_deliveries', 'can_view_own_deliveries'] as $permission) {
    $adminRole->givePermissionTo(Permission::findOrCreate($permission, 'web'));
}
$admin = User::query()->create([
    'name' => 'Security fixture administrator',
    'email' => 'security-admin@example.test',
    'password' => getenv('GAM_SECURITY_FIXTURE_PASSWORD'),
]);
$admin->syncRoles([$adminRole]);
$ownUnit = ProductionUnit::query()->create(['name' => 'Unidad fixture propia', 'latitude' => '-34.900000', 'longitude' => '-56.100000', 'status' => 'active']);
$otherUnit = ProductionUnit::query()->create(['name' => 'Unidad fixture ajena', 'latitude' => '-34.800000', 'longitude' => '-56.200000', 'status' => 'active']);
$employeeRole = Role::findOrCreate('security-employee', 'web');
foreach (['can_login_shared', 'can_view_production_units', 'can_view_inventory'] as $permission) {
    $employeeRole->givePermissionTo(Permission::findOrCreate($permission, 'web'));
}
$employee = new User();
$employee->forceFill([
    'name' => 'Empleado fixture', 'access_method' => 'device_pin', 'production_unit_id' => $ownUnit->id,
    'pin_hash' => app(PinHasher::class)->hash('0007'), 'pin_enabled' => true,
    'pin_pepper_version' => app(PinHasher::class)->pepperVersion(),
])->save();
$employee->syncRoles([$employeeRole]);
$device = SharedDevice::query()->create([
    'name' => 'Dispositivo fixture', 'production_unit_id' => $ownUnit->id,
    'credential_hash' => hash('sha256', getenv('GAM_SECURITY_DEVICE_SECRET')),
    'credential_expires_at' => now()->addDay(), 'enrolled_by' => $admin->id, 'enrolled_at' => now(),
]);
echo json_encode(['own_unit' => $ownUnit->id, 'other_unit' => $otherUnit->id, 'user' => $employee->id, 'device' => $device->id], JSON_THROW_ON_ERROR), PHP_EOL;
