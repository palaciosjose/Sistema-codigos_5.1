<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/security/auth.php';

if (!is_authenticated()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'status' => 'unauthorized',
        'message' => 'Sesión no válida'
    ]);
    exit;
}

require_once __DIR__ . '/license_client.php';

try {
    $client = new ClientLicense();

    // Usar reflexión para acceder al método privado validateWithServer
    $refClass = new ReflectionClass($client);
    $dataMethod = $refClass->getMethod('getLicenseData');
    $dataMethod->setAccessible(true);
    $license_data = $dataMethod->invoke($client);

    if ($license_data) {
        $validateMethod = $refClass->getMethod('validateWithServer');
        $validateMethod->setAccessible(true);
        $validateMethod->invoke($client, $license_data);
    }

    $status = $client->getLicenseStatus();

    echo json_encode([
        'success' => $status['status'] === 'active',
        'status' => $status['status'],
        'message' => $status['message']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
