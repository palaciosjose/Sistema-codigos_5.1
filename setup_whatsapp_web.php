<?php
require_once __DIR__ . '/config/path_constants.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';

use Shared\ConfigService;
use Shared\DatabaseManager;

echo "<h1>🤖 Configuración del Bot de WhatsApp</h1>";

// 1. Verificar archivos clave y autoloader
if (!file_exists(PROJECT_ROOT . '/composer.json')) {
    echo "<p style='color: red;'>❌ Error: composer.json no encontrado</p>";
    exit;
}

if (!file_exists(PROJECT_ROOT . '/vendor/autoload.php')) {
    echo "<p style='color: orange;'>⚠️ Advertencia: vendor/autoload.php no encontrado</p>";
    echo "<p>Ejecuta en terminal: <code>composer install</code></p>";
} else {
    require_once PROJECT_ROOT . '/vendor/autoload.php';
    echo "<p style='color: green;'>✅ Autoloader cargado</p>";
}

// 2. Validar extensiones PHP requeridas
$requiredExtensions = ['mysqli', 'curl', 'json', 'mbstring', 'imap'];
echo "<h2>Extensiones PHP:</h2><ul>";
foreach ($requiredExtensions as $ext) {
    $status = extension_loaded($ext) ? '✅' : '❌';
    echo "<li>{$status} {$ext}</li>";
}
echo "</ul>";

// 3. Verificar conexión a base de datos y crear tablas/columnas
try {
    $db = DatabaseManager::getInstance()->getConnection();
    echo "<p style='color: green;'>✅ Conexión a base de datos exitosa</p>";

    // Tabla whatsapp_temp_data
    $sql = "CREATE TABLE IF NOT EXISTS whatsapp_temp_data (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        data_type VARCHAR(100) NOT NULL,
        data_content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_type (user_id, data_type),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->query($sql);

    // Tabla whatsapp_activity_log
    $sql = "CREATE TABLE IF NOT EXISTS whatsapp_activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        whatsapp_id BIGINT NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_whatsapp_id (whatsapp_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->query($sql);

    // Tabla whatsapp_sessions necesaria para autenticación
    $sql = "CREATE TABLE IF NOT EXISTS whatsapp_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        whatsapp_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        session_token VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_whatsapp_id (whatsapp_id),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->query($sql);

    // Columnas en users
    $result = $db->query("SHOW COLUMNS FROM users LIKE 'whatsapp_id'");
    if ($result && $result->num_rows === 0) {
        $db->query("ALTER TABLE users ADD COLUMN whatsapp_id BIGINT NULL AFTER telegram_id");
        echo "<p>✅ Columna 'whatsapp_id' agregada a users</p>";
    }
    if ($result) { $result->close(); }

    $result = $db->query("SHOW COLUMNS FROM users LIKE 'last_whatsapp_activity'");
    if ($result && $result->num_rows === 0) {
        $db->query("ALTER TABLE users ADD COLUMN last_whatsapp_activity TIMESTAMP NULL AFTER last_telegram_activity");
        echo "<p>✅ Columna 'last_whatsapp_activity' agregada a users</p>";
    }
    if ($result) { $result->close(); }

    // Columnas en search_logs
    $result = $db->query("SHOW COLUMNS FROM search_logs LIKE 'whatsapp_chat_id'");
    if ($result && $result->num_rows === 0) {
        $db->query("ALTER TABLE search_logs ADD COLUMN whatsapp_chat_id VARCHAR(255) NULL AFTER telegram_chat_id");
        echo "<p>✅ Columna 'whatsapp_chat_id' agregada a search_logs</p>";
    }
    if ($result) { $result->close(); }

    $result = $db->query("SHOW COLUMNS FROM search_logs LIKE 'source'");
    if ($result && $result->num_rows === 0) {
        $db->query("ALTER TABLE search_logs ADD COLUMN source VARCHAR(50) DEFAULT 'web' AFTER whatsapp_chat_id");
        echo "<p>✅ Columna 'source' agregada a search_logs</p>";
    }
    if ($result) { $result->close(); }

    // Configuraciones iniciales para el bot de WhatsApp
    $settings = [
        ['WHATSAPP_API_URL', getenv('WHATSAPP_API_URL') ?: '', 'URL base de la API de WhatsApp', 'whatsapp'],
        ['WHATSAPP_API_TOKEN', getenv('WHATSAPP_API_TOKEN') ?: '', 'Token de la API de WhatsApp', 'whatsapp'],
        ['WHATSAPP_INSTANCE_ID', getenv('WHATSAPP_INSTANCE_ID') ?: '', 'ID de la instancia de WhatsApp', 'whatsapp'],
        ['WHATSAPP_BOT_WEBHOOK', getenv('WHATSAPP_BOT_WEBHOOK') ?: '', 'URL del webhook del bot de WhatsApp', 'whatsapp'],
        ['WHATSAPP_BOT_ENABLED', getenv('WHATSAPP_BOT_ENABLED') ?: '0', 'Bot de WhatsApp habilitado (1=Sí,0=No)', 'whatsapp'],
        ['WHATSAPP_WEBHOOK_SECRET', getenv('WHATSAPP_WEBHOOK_SECRET') ?: '', 'Secreto para validar webhooks de WhatsApp', 'whatsapp']
    ];

    $stmt = $db->prepare("INSERT INTO settings (name, value, description, category) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    if ($stmt) {
        foreach ($settings as $setting) {
            $stmt->bind_param('ssss', $setting[0], $setting[1], $setting[2], $setting[3]);
            $stmt->execute();
        }
        $stmt->close();
        echo "<p style='color: green;'>✅ Configuración de WhatsApp guardada</p>";
    } else {
        echo "<p style='color: red;'>❌ No se pudo preparar la consulta de configuración</p>";
    }

    echo "<p style='color: green;'>✅ Verificación de tablas y columnas completada</p>";
} catch (\Throwable $e) {
    echo "<p style='color: red;'>❌ Error de base de datos: " . htmlspecialchars($e->getMessage()) . "</p>";
}
