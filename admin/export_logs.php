<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once PROJECT_ROOT . '/shared/AuditLogger.php';
require_once SECURITY_DIR . '/auth.php';

use Shared\DatabaseManager;
use Shared\AuditLogger;

authorize('view_logs', '../index.php', false);

try {
    $conn = DatabaseManager::getInstance()->getConnection();
} catch (Throwable $e) {
    die('Error de conexión: ' . $e->getMessage());
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="logs_export_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Usuario', 'Email', 'Plataforma', 'IP', 'Fecha']);

$query = $conn->query("SELECT l.id, u.username, l.email_consultado, l.plataforma, l.ip, l.fecha FROM logs l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.fecha DESC");
if ($query) {
    while ($row = $query->fetch_assoc()) {
        fputcsv($out, $row);
    }
    $query->close();
}

fclose($out);
AuditLogger::log($_SESSION['user_id'] ?? null, 'export_logs');
exit;
