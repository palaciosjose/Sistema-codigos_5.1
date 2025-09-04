<?php
session_start();

// Verificar rol de usuario
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo 'Acceso denegado';
    exit;
}

// Procesar solicitud POST para ejecutar comandos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(400);
        echo 'Token CSRF inválido';
        exit;
    }

    // Definir acciones permitidas
    $acciones = [
        'purge_audit_logs' => 'php ../scripts/purge_audit_logs.php'
    ];

    $accion = $_POST['accion'] ?? '';
    if (!array_key_exists($accion, $acciones)) {
        http_response_code(400);
        echo 'Acción no permitida';
        exit;
    }

    $comando = $acciones[$accion];
    $resultado = shell_exec($comando . ' 2>&1');

    // Registrar en log
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $logEntry = sprintf("%s [%s] %s\n", date('c'), $accion, $resultado);
    file_put_contents($logDir . '/cli.log', $logEntry, FILE_APPEND);

    echo '<pre>' . htmlspecialchars($resultado) . '</pre>';
    exit;
}

// Mostrar formulario
$csrf_token = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CLI Seguro</title>
</head>
<body>
<form method="POST">
    <select name="accion">
        <option value="purge_audit_logs">Purgar registros de auditoría</option>
    </select>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <button type="submit">Ejecutar</button>
</form>
</body>
</html>
