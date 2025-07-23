<?php
/**
 * API para obtener estadísticas básicas del bot de Telegram
 * Devuelve total de logs, logs de hoy y usuarios únicos
 */
require_once '../libs/db_util.php';
session_start();
require_once '../instalacion/basededatos.php';
require_once '../security/auth.php';

// Verificar autenticación de administrador
check_session(true, '../index.php');

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$conn = new mysqli($db_host, $db_user, $db_password, $db_name);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    echo json_encode(['error' => 'Error de conexión a la base de datos']);
    exit();
}

$stats = ['total_logs' => 0, 'logs_today' => 0, 'unique_users' => 0];

try {
    $res_total = $conn->query("SELECT COUNT(*) as total FROM telegram_bot_logs");
    if ($res_total) {
        $stats['total_logs'] = (int)$res_total->fetch_assoc()['total'];
    }

    $res_today = $conn->query("SELECT COUNT(*) as total FROM telegram_bot_logs WHERE DATE(created_at) = CURDATE()");
    if ($res_today) {
        $stats['logs_today'] = (int)$res_today->fetch_assoc()['total'];
    }

    $res_users = $conn->query("SELECT COUNT(DISTINCT telegram_id) as total FROM telegram_bot_logs WHERE telegram_id IS NOT NULL");
    if ($res_users) {
        $stats['unique_users'] = (int)$res_users->fetch_assoc()['total'];
    }

    echo json_encode($stats, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['error' => 'Error al obtener estadísticas: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>
