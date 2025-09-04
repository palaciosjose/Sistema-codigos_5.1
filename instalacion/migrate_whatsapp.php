<?php
// instalacion/migrate_whatsapp.php
// Ejecuta consultas de creación/alteración para soporte de WhatsApp

require_once __DIR__ . '/../shared/DatabaseManager.php';

use Shared\DatabaseManager;

try {
    $db = DatabaseManager::getInstance()->getConnection();
    echo "Conexión a la base de datos establecida\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Error de conexión: " . $e->getMessage() . "\n");
    exit(1);
}

$queries = [
    'whatsapp_temp_data' => "CREATE TABLE IF NOT EXISTS whatsapp_temp_data (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        data_type VARCHAR(50) NOT NULL,
        data_content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_type (user_id, data_type),
        INDEX idx_user_id (user_id),
        INDEX idx_data_type (data_type),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
    'whatsapp_activity_log' => "CREATE TABLE IF NOT EXISTS whatsapp_activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        whatsapp_id BIGINT NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_whatsapp_id (whatsapp_id),
        INDEX idx_created_at (created_at),
        INDEX idx_action (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
    'whatsapp_sessions' => "CREATE TABLE IF NOT EXISTS whatsapp_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        whatsapp_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        session_token VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_whatsapp_id (whatsapp_id),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci"
];

foreach ($queries as $name => $sql) {
    if ($db->query($sql) === true) {
        echo "✅ Tabla {$name} verificada/creada\n";
    } else {
        echo "❌ Error creando {$name}: {$db->error}\n";
    }
}

$columns = [
    ['users', 'whatsapp_id', "ALTER TABLE users ADD COLUMN whatsapp_id BIGINT NULL UNIQUE AFTER last_telegram_activity"],
    ['users', 'whatsapp_username', "ALTER TABLE users ADD COLUMN whatsapp_username VARCHAR(255) NULL AFTER whatsapp_id"],
    ['users', 'last_whatsapp_activity', "ALTER TABLE users ADD COLUMN last_whatsapp_activity TIMESTAMP NULL AFTER whatsapp_username"],
    ['search_logs', 'whatsapp_chat_id', "ALTER TABLE search_logs ADD COLUMN whatsapp_chat_id BIGINT NULL AFTER telegram_chat_id, ADD INDEX idx_whatsapp_chat (whatsapp_chat_id)"],
    ['search_logs', 'source', "ALTER TABLE search_logs ADD COLUMN source VARCHAR(50) DEFAULT 'web' AFTER whatsapp_chat_id"],
];

foreach ($columns as [$table, $column, $sql]) {
    $result = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    if ($result && $result->num_rows > 0) {
        echo "ℹ️ Columna {$table}.{$column} ya existe\n";
        $result->close();
        continue;
    }
    if ($db->query($sql) === true) {
        echo "✅ Columna {$table}.{$column} agregada\n";
    } else {
        echo "❌ Error agregando {$table}.{$column}: {$db->error}\n";
    }
}

echo "Migración completada\n";
