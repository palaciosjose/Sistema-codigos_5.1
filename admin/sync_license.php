<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();
require_once SECURITY_DIR . '/auth.php';
authorize('sync_license', '../index.php', false);
require_once PROJECT_ROOT . '/license_client.php';

try {
    $client = new ClientLicense();
    $client->forceRemoteValidation();
    $info = $client->getLicenseInfo();

    if (!empty($info['license_type']) && !empty($info['expires_at'])) {
        $data = [
            'license_key'   => $info['license_key'],
            'domain'        => $info['domain'],
            'activated_at'  => $info['activated_at'],
            'status'        => $info['status'],
            'expires_at'    => $info['expires_at'],
            'last_check'    => strtotime($info['last_check']),
            'license_type'  => $info['license_type']
        ];

        $encoded = base64_encode(serialize($data));
        $result  = file_put_contents(LICENSE_FILE, $encoded, LOCK_EX);
        if ($result !== false) {
            $_SESSION['license_sync_message'] = 'Licencia verificada y actualizada.';
        } else {
            $_SESSION['license_sync_message'] = 'No se pudo escribir el archivo de licencia.';
        }
    } else {
        $_SESSION['license_sync_message'] = 'Validación completada pero faltan datos de la licencia.';
    }
} catch (Exception $e) {
    $_SESSION['license_sync_message'] = 'Error durante la validación: ' . $e->getMessage();
}

header('Location: admin.php?tab=licencia');
exit;