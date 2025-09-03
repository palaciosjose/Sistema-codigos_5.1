<?php
require_once __DIR__ . '/config/path_constants.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
/**
 * Script de Validación de Tablas - Web Códigos 5.0
 * Verifica que todas las tablas necesarias estén creadas en la base de datos
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Validador de Tablas - Web Códigos 5.0</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; }
        .status { margin: 10px 0; padding: 10px; border-radius: 5px; }
        .ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .table-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin: 20px 0; }
        .table-item { padding: 10px; border-radius: 5px; border-left: 4px solid #007bff; }
        .table-exists { background: #d4edda; border-left-color: #28a745; }
        .table-missing { background: #f8d7da; border-left-color: #dc3545; }
        .summary { margin: 20px 0; padding: 15px; background: #e9ecef; border-radius: 5px; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
        .btn:hover { background: #0056b3; }
        .sql-script { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 10px 0; max-height: 300px; overflow-y: auto; }
        .sql-script pre { margin: 0; white-space: pre-wrap; }
    </style>
</head>
<body>";

echo "<div class='container'>";
echo "<div class='header'>";
echo "<h1>🗃️ Validador de Tablas - Web Códigos 5.0</h1>";
echo "<p>Verificando la integridad de la base de datos...</p>";
echo "</div>";

// Función para obtener configuración de la base de datos
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

// Definir todas las tablas requeridas
$requiredTables = [
    // Tablas principales del sistema
    'admin' => 'Administradores del sistema',
    'users' => 'Usuarios del sistema',
    'settings' => 'Configuraciones globales',
    
    // Servidores de correo
    'email_servers' => 'Servidores IMAP configurados',
    
    // Plataformas y asuntos
    'platforms' => 'Plataformas disponibles (Netflix, Amazon, etc.)',
    'platform_subjects' => 'Asuntos permitidos por plataforma',
    
    // Correos autorizados y permisos
    'authorized_emails' => 'Lista de correos autorizados',
    'user_authorized_emails' => 'Asignación de correos a usuarios',
    'user_platform_subjects' => 'Permisos de usuarios por plataforma',
    
    // Sistema de logs
    'logs' => 'Registro de búsquedas del sistema web',
    
    // Bot de Telegram
    'telegram_bot_logs' => 'Logs del bot de Telegram',
    'telegram_sessions' => 'Sesiones activas del bot',
    'telegram_temp_data' => 'Datos temporales del bot',
    'telegram_activity_log' => 'Actividad del bot',
    
    // Plantillas y grupos (opcional)
    'user_permission_templates' => 'Plantillas de permisos',
    'user_groups' => 'Grupos de usuarios',
    
    // Logs de búsqueda (para bot)
    'search_logs' => 'Logs de búsquedas del bot'
];

try {
    // Obtener configuración de la base de datos
    $config = getDatabaseConfig();
    
    if (!$config || empty($config['host']) || empty($config['database'])) {
        throw new Exception('No se pudo obtener la configuración de la base de datos. Verifica que exista config/db_credentials.php o las variables de entorno.');
    }
    
    echo "<div class='status ok'>";
    echo "✅ <strong>Configuración encontrada:</strong><br>";
    echo "📍 Host: " . htmlspecialchars($config['host']) . "<br>";
    echo "🗄️ Base de datos: " . htmlspecialchars($config['database']) . "<br>";
    echo "👤 Usuario: " . htmlspecialchars($config['user']);
    echo "</div>";
    
    // Conectar a la base de datos
    $conn = DatabaseManager::getInstance()->getConnection();
    
    echo "<div class='status ok'>";
    echo "✅ <strong>Conexión exitosa</strong> - Server info: " . $conn->server_info;
    echo "</div>";
    
    // Verificar cada tabla
    $existingTables = [];
    $missingTables = [];
    $tableDetails = [];
    
    foreach ($requiredTables as $tableName => $description) {
        $result = $conn->query("SHOW TABLES LIKE '$tableName'");
        
        if ($result && $result->num_rows > 0) {
            $existingTables[] = $tableName;
            
            // Obtener información adicional de la tabla
            $countResult = $conn->query("SELECT COUNT(*) as count FROM `$tableName`");
            $count = $countResult ? $countResult->fetch_assoc()['count'] : 0;
            
            $tableDetails[$tableName] = [
                'exists' => true,
                'count' => $count,
                'description' => $description
            ];
        } else {
            $missingTables[] = $tableName;
            $tableDetails[$tableName] = [
                'exists' => false,
                'count' => 0,
                'description' => $description
            ];
        }
    }
    
    // Mostrar resumen
    $totalTables = count($requiredTables);
    $existingCount = count($existingTables);
    $missingCount = count($missingTables);
    
    echo "<div class='summary'>";
    echo "<h3>📊 Resumen General</h3>";
    echo "<p><strong>Total de tablas requeridas:</strong> $totalTables</p>";
    echo "<p><strong>Tablas existentes:</strong> $existingCount</p>";
    echo "<p><strong>Tablas faltantes:</strong> $missingCount</p>";
    
    if ($missingCount == 0) {
        echo "<div class='status ok'>";
        echo "🎉 <strong>¡Todas las tablas están presentes!</strong> La base de datos está completa.";
        echo "</div>";
    } else {
        echo "<div class='status error'>";
        echo "⚠️ <strong>Faltan $missingCount tablas.</strong> El sistema puede no funcionar correctamente.";
        echo "</div>";
    }
    echo "</div>";
    
    // Mostrar detalles de cada tabla
    echo "<h3>📋 Detalles de Tablas</h3>";
    echo "<div class='table-list'>";
    
    foreach ($requiredTables as $tableName => $description) {
        $details = $tableDetails[$tableName];
        $class = $details['exists'] ? 'table-exists' : 'table-missing';
        $icon = $details['exists'] ? '✅' : '❌';
        
        echo "<div class='table-item $class'>";
        echo "<strong>$icon $tableName</strong><br>";
        echo "<small>$description</small><br>";
        
        if ($details['exists']) {
            echo "<em>Registros: " . number_format($details['count']) . "</em>";
        } else {
            echo "<em style='color: #721c24;'>FALTANTE</em>";
        }
        echo "</div>";
    }
    
    echo "</div>";
    
    // Si hay tablas faltantes, mostrar SQL para crearlas
    if ($missingCount > 0) {
        echo "<h3>🔧 Script de Reparación</h3>";
        echo "<p>Para crear las tablas faltantes, puedes:</p>";
        echo "<ul>";
        echo "<li><a href='instalacion/instalador.php' class='btn'>🚀 Ejecutar Instalador Completo</a></li>";
        echo "<li><a href='create_tables.php' class='btn'>🔨 Crear Solo Tablas del Bot</a></li>";
        echo "<li>O ejecutar manualmente el siguiente SQL:</li>";
        echo "</ul>";
        
        echo "<div class='sql-script'>";
        echo "<h4>SQL para tablas faltantes:</h4>";
        echo "<pre>";
        
        // Generar SQL básico para tablas faltantes críticas
        foreach ($missingTables as $table) {
            switch($table) {
                    
                case 'telegram_sessions':
                    echo "CREATE TABLE telegram_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL UNIQUE,
    user_id INT,
    session_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    INDEX idx_telegram_id (telegram_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

";
                    break;
                    
                case 'telegram_temp_data':
                    echo "CREATE TABLE telegram_temp_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    data_type VARCHAR(50) NOT NULL,
    data JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_user_expires (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

";
                    break;
                    
                default:
                    echo "-- Tabla: $table (consultar instalacion/instalador.php para SQL completo)\n\n";
            }
        }
        
        echo "</pre>";
        echo "</div>";
        
        echo "<div class='status warning'>";
        echo "⚠️ <strong>Recomendación:</strong> Ejecuta el instalador completo si es una instalación nueva, o contacta al desarrollador si es una actualización.";
        echo "</div>";
    }
    
    // Verificaciones adicionales
    echo "<h3>🔍 Verificaciones Adicionales</h3>";
    
    // Verificar estructura de tabla users
    if (in_array('users', $existingTables)) {
        $columnsResult = $conn->query("SHOW COLUMNS FROM users");
        $columns = [];
        while ($row = $columnsResult->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        $requiredColumns = ['telegram_id', 'telegram_username', 'status', 'role'];
        $missingColumns = array_diff($requiredColumns, $columns);
        
        if (empty($missingColumns)) {
            echo "<div class='status ok'>";
            echo "✅ Tabla 'users' tiene todas las columnas necesarias";
            echo "</div>";
        } else {
            echo "<div class='status warning'>";
            echo "⚠️ Tabla 'users' falta columnas: " . implode(', ', $missingColumns);
            echo "</div>";
        }
    }
    
    // Verificar permisos de directorio
    $directories = ['images/platforms/', 'telegram_bot/logs/', 'config/'];
    foreach ($directories as $dir) {
        if (is_dir($dir)) {
            $writable = is_writable($dir);
            $class = $writable ? 'ok' : 'warning';
            $icon = $writable ? '✅' : '⚠️';
            echo "<div class='status $class'>";
            echo "$icon Directorio '$dir': " . ($writable ? 'Escribible' : 'Sin permisos de escritura');
            echo "</div>";
        } else {
            echo "<div class='status warning'>";
            echo "⚠️ Directorio '$dir' no existe";
            echo "</div>";
        }
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<div class='status error'>";
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
    
    echo "<h3>🔧 Posibles soluciones:</h3>";
    echo "<ul>";
    echo "<li>Verifica que el archivo <code>config/db_credentials.php</code> exista y tenga las credenciales correctas</li>";
    echo "<li>Asegúrate de que el servidor MySQL esté funcionando</li>";
    echo "<li>Verifica que el usuario de la base de datos tenga los permisos necesarios</li>";
    echo "<li>Si es una instalación nueva, ejecuta <code>instalacion/instalador.php</code></li>";
    echo "</ul>";
}

echo "<div style='margin-top: 30px; text-align: center; color: #6c757d;'>";
echo "<small>Script generado para Web Códigos 5.0 - " . date('Y-m-d H:i:s') . "</small>";
echo "</div>";

echo "</div>";
echo "</body></html>";
?>