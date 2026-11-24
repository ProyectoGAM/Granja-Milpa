<?php

declare(strict_types=1);

$base = 'http://web:8080';
$results = [];
$token = null;

function request(string $method, string $path, ?array $body = null, ?string $token = null, ?string $key = null, array $extraHeaders = []): array
{
    global $base;
    $headers = ["Accept: application/json", "Content-Type: application/json"];
    if ($key !== null) {
        $headers[] = 'Idempotency-Key: '.$key;
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $headers = [...$headers, ...$extraHeaders];
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers)."\r\n",
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'timeout' => 10,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents($base.$path, false, $context);
    if ($body === false) {
        throw new RuntimeException('No se pudo conectar a '.$path);
    }
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($body, true)];
}

function expect(string $name, int $actual, int $expected): void
{
    global $results;
    $results[] = ['name' => $name, 'status' => $actual, 'expected' => $expected, 'passed' => $actual === $expected];
}

try {
    $fixture = json_decode(getenv('GAM_SECURITY_FIXTURE_IDS'), true, flags: JSON_THROW_ON_ERROR);
    $deviceHeaders = ['X-Shared-Device-Token: '.$fixture['device'].'|'.getenv('GAM_SECURITY_DEVICE_SECRET')];
    [$status, $pinLogin] = request('POST', '/api/v1/shared-device/login-pin', ['user_id' => $fixture['user'], 'pin' => '0007'], extraHeaders: $deviceHeaders);
    expect('inicio PIN compartido', $status, 200);
    $sharedToken = $pinLogin['access_token'] ?? null;
    $session = $pinLogin['session']['id'] ?? null;
    if (!is_string($sharedToken) || !is_string($session)) {
        throw new RuntimeException('El acceso PIN no devolvió su contexto de sesión');
    }
    $sharedHeaders = [...$deviceHeaders, 'X-GAM-Session: '.$session];
    [$status, $units] = request('GET', '/api/v1/production-units', token: $sharedToken, extraHeaders: $sharedHeaders);
    expect('lista de unidades autorizadas', $status, 200);
    if (count($units['data'] ?? []) !== 1 || ($units['data'][0]['id'] ?? null) !== $fixture['own_unit']) {
        throw new RuntimeException('La lista de unidades no respeta el alcance del dispositivo');
    }
    [$status] = request('GET', '/api/v1/production-units/'.$fixture['other_unit'], token: $sharedToken, extraHeaders: $sharedHeaders);
    expect('unidad ajena', $status, 404);
    [$status] = request('GET', '/api/v1/inventory/balances?production_unit_id='.$fixture['other_unit'], token: $sharedToken, extraHeaders: $sharedHeaders);
    expect('inventario de unidad ajena', $status, 404);
    [$status] = request('GET', '/api/v1/me', token: $sharedToken, extraHeaders: $deviceHeaders);
    expect('PIN sin contexto de sesión', $status, 401);
    [$status] = request('GET', '/api/v1/clientes');
    expect('clientes sin credenciales', $status, 401);

    [$status] = request('POST', '/api/v1/auth/login', [
        'email' => 'security-fixture@example.test',
        'password' => 'incorrect-fixture',
        'device_name' => 'security-fixture',
    ]);
    expect('credenciales inválidas', $status, 401);

    [$status, $login] = request('POST', '/api/v1/auth/login', [
        'email' => 'security-fixture@example.test',
        'password' => getenv('GAM_SECURITY_FIXTURE_PASSWORD'),
        'device_name' => 'security-fixture',
    ]);
    expect('inicio de sesión', $status, 200);
    $token = $login['access_token'] ?? null;
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('El inicio de sesión no devolvió una credencial válida');
    }

    [$status, $profile] = request('GET', '/api/v1/me', token: $token);
    expect('perfil autenticado', $status, 200);
    if (($profile['data']['email'] ?? null) !== 'security-fixture@example.test') {
        throw new RuntimeException('El perfil autenticado no corresponde a la cuenta del fixture');
    }

    [$status] = request('GET', '/api/v1/clientes', token: $token);
    expect('clientes sin permiso', $status, 403);

    [$status] = request('POST', '/api/v1/auth/logout', [], $token);
    expect('cierre de sesión', $status, 200);
    [$status] = request('GET', '/api/v1/me', token: $token);
    expect('sesión revocada', $status, 401);

    [$status, $login] = request('POST', '/api/v1/auth/login', [
        'email' => 'security-admin@example.test',
        'password' => getenv('GAM_SECURITY_FIXTURE_PASSWORD'),
        'device_name' => 'security-fixture-admin',
    ]);
    expect('inicio de administrador', $status, 200);
    $adminToken = $login['access_token'] ?? null;
    if (!is_string($adminToken) || $adminToken === '') {
        throw new RuntimeException('El administrador no obtuvo una sesión válida');
    }
    [$status, $customersBefore] = request('GET', '/api/v1/clientes', token: $adminToken);
    expect('clientes con permiso', $status, 200);
    [$status] = request('POST', '/api/v1/clientes', ['latitude' => '-34.7'], $adminToken, '00000000-0000-4000-8000-000000000001');
    expect('cliente inválido', $status, 422);
    [$status, $customersAfter] = request('GET', '/api/v1/clientes', token: $adminToken);
    expect('clientes tras validación', $status, 200);
    if (!isset($customersBefore['meta']['total'], $customersAfter['meta']['total'])
        || $customersBefore['meta']['total'] !== $customersAfter['meta']['total']) {
        throw new RuntimeException('La entrada inválida modificó los clientes');
    }
    [$status, $created] = request('POST', '/api/v1/clientes', ['name' => 'Cliente fixture'], $adminToken, '00000000-0000-4000-8000-000000000002');
    expect('alta de cliente', $status, 201);
    $customerId = $created['data']['id'] ?? null;
    if (!is_string($customerId) || !preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $customerId)) {
        throw new RuntimeException('El alta no devolvió el identificador del cliente');
    }
    $adjustment = ['direction' => 'increase', 'amount' => '12.625', 'reason' => 'Prueba de seguridad', 'expected_version' => 0];
    $accountPath = '/api/v1/clientes/'.$customerId;
    $adjustmentKey = '00000000-0000-4000-8000-000000000003';
    [$status] = request('POST', $accountPath.'/ajustes', $adjustment, $adminToken, $adjustmentKey);
    expect('ajuste financiero', $status, 200);
    [$status] = request('POST', $accountPath.'/ajustes', $adjustment, $adminToken, $adjustmentKey);
    expect('reintento idempotente', $status, 200);
    [$status] = request('POST', $accountPath.'/ajustes', $adjustment, $adminToken, '00000000-0000-4000-8000-000000000004');
    expect('conflicto de versión financiera', $status, 409);
    [$status, $account] = request('GET', $accountPath.'/cuenta-corriente', token: $adminToken);
    expect('consulta de cuenta', $status, 200);
    if (($account['data']['balance'] ?? null) !== '12.625' || ($account['data']['version'] ?? null) !== 1) {
        throw new RuntimeException('El saldo o la versión cambió durante el reintento o el conflicto');
    }
    [$status, $movements] = request('GET', $accountPath.'/movimientos', token: $adminToken);
    expect('consulta de movimientos', $status, 200);
    if (($movements['meta']['total'] ?? null) !== 1) {
        throw new RuntimeException('El ajuste generó una cantidad inesperada de movimientos');
    }
    [$status, $deliveriesBefore] = request('GET', '/api/v1/repartos/actuales', token: $adminToken);
    expect('consulta de repartos actuales', $status, 200);
    [$status] = request('POST', '/api/v1/repartos', ['pin' => '0007', 'quantity' => -1], $adminToken, '00000000-0000-4000-8000-000000000005');
    expect('reparto inválido', $status, 422);
    [$status, $deliveriesAfter] = request('GET', '/api/v1/repartos/actuales', token: $adminToken);
    expect('consulta tras reparto inválido', $status, 200);
    if (!is_array($deliveriesBefore['data'] ?? null) || ($deliveriesBefore['data'] ?? null) !== ($deliveriesAfter['data'] ?? null)) {
        throw new RuntimeException('El reparto inválido modificó los repartos actuales');
    }
    foreach (['/api/v1/repartos/actuales', '/api/v1/inventory/balances'] as $path) {
        [$status] = request('GET', $path, token: $token);
        expect('sesión revocada '. $path, $status, 401);
    }
    [$status] = request('POST', '/api/v1/auth/logout', [], $adminToken);
    expect('cierre de administrador', $status, 200);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(20);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit(count(array_filter($results, static fn (array $result): bool => !$result['passed'])) ? 10 : 0);
