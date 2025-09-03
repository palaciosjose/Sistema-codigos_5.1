<?php
// Página de renovación de licencia simplificada

require_once __DIR__ . '/config/path_constants.php';
define('INSTALLER_MODE', true);
session_start();
require_once PROJECT_ROOT . '/license_client.php';

// Redirigir al instalador si el sistema no está configurado
if (!file_exists(CONFIG_DIR . '/db_credentials.php')) {
    header('Location: instalacion/instalador.php');
    exit();
}

$license_client     = new ClientLicense();
$license_success    = '';
$license_error      = '';
$renewal_successful = false;

// Procesar renovación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['renew_license'])) {
    $license_key = filter_var(trim($_POST['license_key'] ?? ''), FILTER_SANITIZE_STRING);

    if ($license_key === '') {
        $license_error = 'Por favor, ingrese una clave de licencia válida.';
    } else {
        try {
            $activation = $license_client->activateLicense($license_key);

            if ($activation['success']) {
                $status = $license_client->forceRemoteValidation();
                if ($status['status'] === 'active') {
                    $license_success    = 'Licencia renovada correctamente.';
                    $renewal_successful = true;
                } else {
                    $license_error = 'Licencia renovada pero no pudo verificarse.';
                }
            } else {
                $license_error = $activation['message'];
            }
        } catch (Exception $e) {
            $license_error = 'Error durante la renovación: ' . $e->getMessage();
        }
    }
}

$status_details = $license_client->getLicenseStatus();
$status_map = [
    'active'             => 'Activa',
    'expired'            => 'Expirada',
    'invalid'            => 'No válida',
    'network_error'      => 'Error de red',
    'server_unreachable' => 'Servidor inaccesible',
];
$current_status = $status_map[$status_details['status'] ?? ''] ?? 'Desconocido';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renovar Licencia</title>
    <link rel="stylesheet" href="styles/modern_inicio.css">
</head>
<body>
    <div class="main-container">
        <div class="main-card">
            <h1 class="main-title">Renovar licencia</h1>
            <p>Estado actual: <strong><?= htmlspecialchars($current_status) ?></strong></p>

            <?php if ($license_error): ?>
                <div class="alert-danger-modern"><?= htmlspecialchars($license_error) ?></div>
            <?php endif; ?>

            <?php if ($license_success): ?>
                <div class="alert-success-modern"><?= htmlspecialchars($license_success) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group-modern">
                    <input type="text" name="license_key" class="form-input-modern" placeholder="Nueva clave de licencia" required>
                </div>
                <button type="submit" name="renew_license" class="neon-btn">Renovar</button>
            </form>
        </div>
    </div>

    <?php if ($renewal_successful): ?>
    <script>
        setTimeout(function () {
            if (window !== window.parent) {
                window.parent.postMessage('license-renewed', '*');
            } else {
                window.location.href = 'index.php';
            }
        }, 1500);
    </script>
    <?php endif; ?>
</body>
</html>
