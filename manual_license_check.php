<?php
session_start();
header('Content-Type: application/json');

// Autenticación deshabilitada para esta verificación manual.
// require_once __DIR__ . '/security/auth.php';
// if (!is_authenticated()) {
//     http_response_code(401);
//     echo json_encode([
//         'success' => false,
//         'status' => 'unauthorized'
//     ]);
//     exit;
// }

// Limitador sencillo por IP para evitar abuso
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$limitDir = __DIR__ . '/cache';
if (!is_dir($limitDir)) {
    mkdir($limitDir, 0755, true);
}

$limitFile = $limitDir . '/manual_check_' . md5($ip);
$maxAttempts = 5; // Intentos permitidos por minuto
$interval = 60;    // Ventana de tiempo en segundos

$data = ['count' => 0, 'time' => time()];
if (file_exists($limitFile)) {
    $stored = json_decode(@file_get_contents($limitFile), true);
    if (is_array($stored)) {
        $data = $stored;
    }
    if ($data['time'] <= time() - $interval) {
        $data = ['count' => 0, 'time' => time()];
    }
}

$data['count']++;
file_put_contents($limitFile, json_encode($data));

if ($data['count'] > $maxAttempts) {
    http_response_code(429);
    echo json_encode(['success' => false]);
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
        'status' => $status['status']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'error'
    ]);
}
