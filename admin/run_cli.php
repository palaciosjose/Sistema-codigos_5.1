<?php
session_start();
require_once __DIR__ . '/../security/auth.php';
authorize('manage_whatsapp', '../index.php', false);

// Procesar solicitud POST para ejecutar comandos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
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
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Acción no permitida';
        exit;
    }

    $comando = $acciones[$accion];
    $salida = [];
    $codigo = 0;
    exec($comando . ' 2>&1', $salida, $codigo);
    $resultado = implode("\n", $salida);

    // Registrar en log con usuario y resultado
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    $usuario = $_SESSION['username'] ?? 'desconocido';
    $statusTxt = $codigo === 0 ? 'exito' : 'error';
    $logEntry = sprintf("%s user:%s action:%s status:%s code:%d\n", date('c'), $usuario, $accion, $statusTxt, $codigo);
    file_put_contents($logDir . '/cli.log', $logEntry, FILE_APPEND);

    header('Content-Type: text/plain; charset=utf-8');
    if ($codigo !== 0) {
        http_response_code(500);
    }
    echo $resultado;
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
