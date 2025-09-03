<?php
require_once __DIR__ . '/config/path_constants.php';
session_start();
header('Content-Type: application/json');

// Autenticaci¨®n deshabilitada para esta verificaci¨®n manual.
// require_once __DIR__ . '/security/auth.php';
// if (!is_authenticated()) {
//     http_response_code(401);
//     echo json_encode([
//         'success' => false,
//         'status' => 'unauthorized'
//     ]);
//     exit;
// }

require_once PROJECT_ROOT . '/license_client.php';

try {
    $client = new ClientLicense();
    $client->forceRemoteValidation();
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
