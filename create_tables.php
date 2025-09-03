<?php
require_once __DIR__ . '/config/path_constants.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
// create_tables.php - Crear tablas faltantes para el bot de Telegram
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🗃️ Creador de Tablas para Bot de Telegram</h1>";

use Shared\ConfigService;
use Shared\DatabaseManager;

function getDatabaseConfig() {
    $service = ConfigService::getInstance();
    return [
        'host' => $service->get('DB_HOST', 'localhost'),
        'user' => $service->get('DB_USER', ''),
        'password' => $service->get('DB_PASSWORD', ''),
        'database' => $service->get('DB_NAME', '')
    ];
}

if (isset($_POST['create_tables'])) {
    try {
        $config = getDatabaseConfig();
        if (!$config) {
            throw new Exception('No se pudo obtener la configuración de la base de datos');
        }

        $conn = DatabaseManager::getInstance()->getConnection();
        
        $tables = [];
        
        // 1. Tabla search_logs (principal para el bot)
        $sql1 = "CREATE TABLE IF NOT EXISTS search_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            email VARCHAR(255),
            platform VARCHAR(100),
            status ENUM('searching', 'found', 'not_found', 'error') DEFAULT 'searching',
            result_details TEXT,
            telegram_chat_id BIGINT NULL,
            source VARCHAR(50) DEFAULT 'web',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_telegram_chat_id (telegram_chat_id),
            INDEX idx_created_at (created_at),
            INDEX idx_source (source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($sql1)) {
            $tables[] = "✅ search_logs";
        } else {
            $tables[] = "❌ search_logs: " . $conn->error;
        }
        
        // 2. Tabla telegram_activity_log (opcional)
        $sql2 = "CREATE TABLE IF NOT EXISTS telegram_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_telegram_id (telegram_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($sql2)) {
            $tables[] = "✅ telegram_activity_log";
        } else {
            $tables[] = "❌ telegram_activity_log: " . $conn->error;
        }
        
        // 3. Verificar y actualizar tabla users para telegram_id
        $sql3 = "SHOW COLUMNS FROM users LIKE 'telegram_id'";
        $result = $conn->query($sql3);
        
        if ($result->num_rows == 0) {
            // Agregar columna telegram_id si no existe
            $sql3_add = "ALTER TABLE users ADD COLUMN telegram_id BIGINT NULL AFTER username";
            if ($conn->query($sql3_add)) {
                $tables[] = "✅ users: columna telegram_id agregada";
            } else {
                $tables[] = "❌ users: error agregando telegram_id: " . $conn->error;
            }
            
            // Agregar columna last_telegram_activity
            $sql3_add2 = "ALTER TABLE users ADD COLUMN last_telegram_activity TIMESTAMP NULL";
            if ($conn->query($sql3_add2)) {
                $tables[] = "✅ users: columna last_telegram_activity agregada";
            }
        } else {
            $tables[] = "✅ users: ya tiene columna telegram_id";
        }
        
        // 4. Verificar tabla platforms
        $sql4 = "CREATE TABLE IF NOT EXISTS platforms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            logo VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($sql4)) {
            $tables[] = "✅ platforms";
        }

        // Asegurar que la columna logo exista
        $result_logo = $conn->query("SHOW COLUMNS FROM platforms LIKE 'logo'");
        if ($result_logo && $result_logo->num_rows == 0) {
            if ($conn->query("ALTER TABLE platforms ADD COLUMN logo VARCHAR(255) NULL AFTER description")) {
                $tables[] = "✅ platforms: columna logo agregada";
            } else {
                $tables[] = "❌ platforms: error agregando columna logo: " . $conn->error;
            }
        }

        // Asegurar que la columna sort_order exista
        $result_sort = $conn->query("SHOW COLUMNS FROM platforms LIKE 'sort_order'");
        if ($result_sort && $result_sort->num_rows == 0) {
            if ($conn->query("ALTER TABLE platforms ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER logo")) {
                $tables[] = "✅ platforms: columna sort_order agregada";
            } else {
                $tables[] = "❌ platforms: error agregando columna sort_order: " . $conn->error;
            }
        }

        // Insertar plataformas básicas
        $platforms = ['Netflix', 'Amazon', 'PayPal', 'Steam', 'Epic Games', 'Spotify', 'Apple', 'Google'];
        foreach ($platforms as $platform) {
            $conn->query("INSERT IGNORE INTO platforms (name) VALUES ('$platform')");
        }
        $tables[] = "✅ Plataformas básicas insertadas";
        
        // 5. Verificar tabla servers
        $sql5 = "CREATE TABLE IF NOT EXISTS servers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            host VARCHAR(255) NOT NULL,
            port INT DEFAULT 993,
            username VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            protocol VARCHAR(10) DEFAULT 'imap',
            encryption VARCHAR(10) DEFAULT 'ssl',
            status TINYINT(1) DEFAULT 1,
            priority INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($sql5)) {
            $tables[] = "✅ servers";
        }

        // 6. Tabla audit_log
        $sql6 = "CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(255) NOT NULL,
            ip VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if ($conn->query($sql6)) {
            $tables[] = "✅ audit_log";
        } else {
            $tables[] = "❌ audit_log: " . $conn->error;
        }

        $conn->close();
        
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h3>🎉 Creación de Tablas Completada!</h3>";
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
        echo "<p><strong>Próximos pasos:</strong></p>";
        echo "<ol>";
        echo "<li><a href='telegram_bot/bot_status.php'>🔍 Verificar estado del bot</a></li>";
        echo "<li><a href='telegram_bot/webhook.php'>🔗 Probar webhook</a></li>";
        echo "</ol>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h3>❌ Error:</h3>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
} else {
    // Mostrar formulario
    echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>📋 Tablas que se crearán:</h3>";
    echo "<ul>";
    echo "<li><strong>search_logs:</strong> Para registrar búsquedas del bot</li>";
    echo "<li><strong>telegram_activity_log:</strong> Para actividad de usuarios</li>";
    echo "<li><strong>platforms:</strong> Plataformas disponibles (si no existe)</li>";
    echo "<li><strong>servers:</strong> Servidores de email (si no existe)</li>";
    echo "<li><strong>users:</strong> Agregar columnas telegram_id (si no existe)</li>";
    echo "<li><strong>audit_log:</strong> Registro de acciones críticas</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<form method='POST'>";
    echo "<button type='submit' name='create_tables' style='background: #007bff; color: white; padding: 15px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;'>";
    echo "🗃️ Crear Tablas Necesarias";
    echo "</button>";
    echo "</form>";
}

echo "<p><a href='?delete=1' style='color: red;'>🗑️ Eliminar este archivo después de usar</a></p>";

$delete = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
if ($delete === 1) {
    unlink(__FILE__);
    echo "<p style='color: green;'>✅ Archivo eliminado.</p>";
}
?>