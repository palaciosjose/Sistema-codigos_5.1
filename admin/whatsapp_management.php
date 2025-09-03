<?php
require_once __DIR__ . '/../config/path_constants.php';
session_start();
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once SECURITY_DIR . '/auth.php';
use Shared\DatabaseManager;

authorize('manage_whatsapp', '../index.php', false);

$conn = DatabaseManager::getInstance()->getConnection();

function get_setting($conn, $name) {
    $stmt = $conn->prepare("SELECT value FROM settings WHERE name = ? LIMIT 1");
    if (!$stmt) return '';
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($value);
    $result = $stmt->fetch() ? $value : '';
    $stmt->close();
    return $result;
}

function set_setting($conn, $name, $value) {
    $stmt = $conn->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    if ($stmt) {
        $stmt->bind_param('ss', $name, $value);
        $stmt->execute();
        $stmt->close();
    }
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $api_url = trim($_POST['api_url'] ?? '');
    $token = trim($_POST['token'] ?? '');
    $instance = trim($_POST['instance'] ?? '');
    set_setting($conn, 'WHATSAPP_API_URL', $api_url);
    set_setting($conn, 'WHATSAPP_TOKEN', $token);
    set_setting($conn, 'WHATSAPP_INSTANCE', $instance);
    $message = 'Configuración guardada correctamente.';
}

$api_url = get_setting($conn, 'WHATSAPP_API_URL');
$token = get_setting($conn, 'WHATSAPP_TOKEN');
$instance = get_setting($conn, 'WHATSAPP_INSTANCE');
$webhook_url = get_setting($conn, 'WHATSAPP_WEBHOOK_URL');
$webhook_status = $webhook_url ? 'Configurado' : 'No configurado';

$authorized_users = [];
$auth_res = $conn->query("SELECT u.username, ae.email FROM user_authorized_emails uae JOIN users u ON uae.user_id = u.id JOIN authorized_emails ae ON uae.authorized_email_id = ae.id ORDER BY u.username");
if ($auth_res) {
    while ($row = $auth_res->fetch_assoc()) {
        $authorized_users[] = $row;
    }
    $auth_res->close();
}

function get_recent_logs($file, $lines = 20) {
    if (!file_exists($file)) return [];
    return array_slice(file($file), -$lines);
}

$error_log = get_recent_logs(PROJECT_ROOT . '/whatsapp_bot/logs/error.log');
$bot_log = get_recent_logs(PROJECT_ROOT . '/whatsapp_bot/logs/bot.log');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión Bot WhatsApp</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../styles/modern_global.css">
</head>
<body class="bg-dark text-white">
<div class="container py-4">
    <h1 class="mb-4">Gestión Bot WhatsApp</h1>
    <?php if (!empty($message)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="post" class="mb-4">
        <div class="mb-3">
            <label class="form-label">API URL</label>
            <input type="text" name="api_url" class="form-control" value="<?php echo htmlspecialchars($api_url); ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Token</label>
            <input type="text" name="token" class="form-control" value="<?php echo htmlspecialchars($token); ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Instancia</label>
            <input type="text" name="instance" class="form-control" value="<?php echo htmlspecialchars($instance); ?>">
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>

    <div class="mb-4">
        <h2>Estado del Webhook</h2>
        <p><?php echo htmlspecialchars($webhook_status); ?><?php if ($webhook_url) echo ': ' . htmlspecialchars($webhook_url); ?></p>
    </div>

    <div class="mb-4">
        <h2>Usuarios Autorizados</h2>
        <?php if (empty($authorized_users)): ?>
            <p>No hay usuarios autorizados.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($authorized_users as $u): ?>
                    <li><?php echo htmlspecialchars($u['username'] . ' - ' . $u['email']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="mb-4">
        <h2>Logs Recientes</h2>
        <?php if (!$error_log && !$bot_log): ?>
            <p>No hay logs disponibles.</p>
        <?php else: ?>
            <?php if ($error_log): ?>
                <h3>Error Log</h3>
                <pre class="bg-dark text-light p-2 border"><?php echo htmlspecialchars(implode('', $error_log)); ?></pre>
            <?php endif; ?>
            <?php if ($bot_log): ?>
                <h3>Bot Log</h3>
                <pre class="bg-dark text-light p-2 border"><?php echo htmlspecialchars(implode('', $bot_log)); ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <a href="admin.php" class="btn btn-secondary">Volver</a>
</div>
</body>
</html>
