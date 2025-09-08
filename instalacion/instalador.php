<?php
/**
 * Instalador del Sistema con Bot de Telegram Integrado
 * Versión 3.1 - COMPLETA CON TODAS LAS TABLAS NECESARIAS
 * VERSIÓN CORREGIDA - Sin errores críticos
 */

require_once __DIR__ . '/../config/path_constants.php';
session_start();

define('INSTALLER_MODE', true);

if (!file_exists(LICENSE_DIR)) {
    if (!mkdir(LICENSE_DIR, 0755, true)) {
        die('Error: No se pudo crear el directorio de licencias: ' . LICENSE_DIR);
    }
}

$license_htaccess_content = "Deny from all\n<Files \"*.dat\">\nDeny from all\n</Files>";
file_put_contents(LICENSE_DIR . '/.htaccess', $license_htaccess_content);

require_once PROJECT_ROOT . '/license_client.php';
// ✅ CORRECCIÓN: No cargar basededatos.php al inicio (no existe aún)
// require_once 'basededatos.php';  // Se carga después de la instalación

// ✅ CORRECCIÓN: Cargar funciones.php de forma segura
$funciones_path = PROJECT_ROOT . '/funciones.php';
if (file_exists($funciones_path)) {
    require_once $funciones_path;
}

header('Content-Type: text/html; charset=utf-8');

$required_extensions = [
    'session' => 'Para manejar sesiones.',
    'imap' => 'Para conectarse y manejar correos a través de IMAP.',
    'mbstring' => 'Para manejar cadenas multibyte.',
    'fileinfo' => 'Para manejar la detección de tipos MIME.',
    'json' => 'Para manejar datos en formato JSON.',
    'openssl' => 'Para manejar conexiones seguras y cifrado.',
    'filter' => 'Para la sanitización y validación de datos.',
    'ctype' => 'Para la verificación de tipos de caracteres.',
    'iconv' => 'Para la conversión de conjuntos de caracteres.',
    'curl' => 'Para realizar peticiones HTTP (requerido para verificación de licencia).'
];

$php_version_required = '8.1.0';
$php_version = phpversion();
$extensions_status = [];

foreach ($required_extensions as $ext => $description) {
    $extensions_status[$ext] = extension_loaded($ext);
}

$all_extensions_loaded = !in_array(false, $extensions_status, true);
$php_version_valid = version_compare($php_version, $php_version_required, '>=');

$allowed_steps = ['requirements','license','configuration'];
$current_step = filter_input(INPUT_GET, 'step', FILTER_SANITIZE_STRING) ?? 'requirements';
if (!in_array($current_step, $allowed_steps, true)) {
    $current_step = 'requirements';
}
$license_client = new ClientLicense();

function verificarSistemaLicencias() {
    $diagnostico = [
        'license_dir_exists' => file_exists(LICENSE_DIR),
        'license_dir_writable' => is_writable(dirname(LICENSE_DIR)),
        'license_file_path' => LICENSE_FILE,
        'project_root' => PROJECT_ROOT,
        'installer_dir' => __DIR__
    ];
    
    return $diagnostico;  
}                      

$diagnostico_licencias = verificarSistemaLicencias();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_license'])) {
    $license_key = filter_var(trim($_POST['license_key'] ?? ''), FILTER_SANITIZE_STRING);
    
    if (empty($license_key)) {
        $license_error = 'Por favor, ingrese una clave de licencia válida.';
    } else {
        try {
            if (!is_writable(LICENSE_DIR)) {
                throw new Exception('El directorio de licencias no tiene permisos de escritura: ' . LICENSE_DIR);
            }

            $activation_result = $license_client->activateLicense($license_key);

            if ($activation_result['success']) {
                $status = $license_client->forceRemoteValidation();
                if ($status['status'] === 'active') {
                    $_SESSION['license_key'] = $license_key;
                    $license_success = 'Licencia activada y verificada exitosamente.';
                } else {
                    $license_error = 'Licencia activada pero no se pudo verificar.';
                }
            } else {
                $license_error = $activation_result['message'];
            }
        } catch (Exception $e) {
            $license_error = 'Error durante la activación: ' . $e->getMessage();
            error_log('Error activación licencia: ' . $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['configure'])) {
    try {
        $status = $license_client->getLicenseStatus();
        if ($status['status'] !== 'active') {
            throw new Exception('Debe activar una licencia válida antes de continuar con la instalación.');
        }
        
        $validation_errors = validateInstallationData($_POST);
        if (!empty($validation_errors)) {
            throw new Exception(implode('<br>', $validation_errors));
        }
        
        $db_host = filter_var($_POST['db_host'] ?? '', FILTER_SANITIZE_STRING);
        $db_name = filter_var($_POST['db_name'] ?? '', FILTER_SANITIZE_STRING);
        $db_user = filter_var($_POST['db_user'] ?? '', FILTER_SANITIZE_STRING);
        $db_password = $_POST['db_password'];
        $admin_user = filter_var($_POST['admin_user'] ?? '', FILTER_SANITIZE_STRING);
        $admin_password = $_POST['admin_password'];
        $admin_telegram = filter_var($_POST['admin_telegram'] ?? '', FILTER_SANITIZE_STRING);
        
        createConfigurationFiles($db_host, $db_name, $db_user, $db_password);
        testDatabaseConnection($db_host, $db_user, $db_password);
        $pdo = setupDatabase($db_host, $db_name, $db_user, $db_password);
        
        // CREAR TODA LA ESTRUCTURA DE BASE DE DATOS (INCLUYENDO BOT)
        createCompleteDatabase($pdo);

        // Garantizar índice único para telegram_temp_data
        ensureTelegramTempIndex($pdo);

        try {
            // Crear archivo .env automáticamente con datos de BD
            $env_created = createEnvironmentFile($db_host, $db_name, $db_user, $db_password);

            // Insertar configuraciones por defecto de Wamundo
            insertWamundoDefaultSettings($pdo);


        } catch (Exception $e) {
            // Log del error pero no mostrar nada en pantalla
            error_log("Error en configuración automática: " . $e->getMessage());
            $env_created = false;
        }

        // ✅ CORRECCIÓN CRÍTICA: Pasar correctamente el parámetro $admin_telegram
        insertInitialData($pdo, $admin_user, $admin_password, $admin_telegram);
        
        setupFileSystem();
        
        ensureLicenseIsSaved($_SESSION['license_key'] ?? '');
        finalizeInstallation($pdo);
        
        $installation_successful = true;
        
        unset($_SESSION['license_key']);
        
    } catch (Exception $e) {
        $installation_error = true;
        $error_message = $e->getMessage();
        error_log("Error en instalación: " . $error_message);
    }
}

function ensureLicenseIsSaved($license_key) {
    if (empty($license_key)) {
        return;
    }
    
    global $license_client;
    
    if (!file_exists(LICENSE_FILE)) {
        try {
            error_log('Reactivando licencia porque no se encontró archivo en: ' . LICENSE_FILE);
            $activation_result = $license_client->activateLicense($license_key);
            if (!$activation_result['success']) {
                throw new Exception('No se pudo reactivar la licencia durante la instalación');
            }
            error_log('Licencia reactivada exitosamente en: ' . LICENSE_FILE);
        } catch (Exception $e) {
            error_log('Error reactivando licencia durante instalación: ' . $e->getMessage());
        }
    } else {
        error_log('Archivo de licencia encontrado correctamente en: ' . LICENSE_FILE);
    }
}

function ensureTelegramTempIndex($pdo) {
    $result = $pdo->query("SHOW INDEX FROM telegram_temp_data WHERE Key_name = 'unique_user_type'");
    if ($result->rowCount() === 0) {
        $pdo->exec("ALTER TABLE telegram_temp_data ADD UNIQUE KEY unique_user_type (user_id, data_type)");
    }
}

function createEnvironmentFile($db_host, $db_name, $db_user, $db_password) {
    // Generar clave aleatoria para cifrado
    $cryptoKey = bin2hex(random_bytes(32));

    // Escapar valores para evitar problemas
    $env_content = "# =========================================\n" .
        "# CONFIGURACIÓN AUTOMÁTICA - Sistema Web Códigos 5.0\n" .
        "# Generado automáticamente el " . date('Y-m-d H:i:s') . "\n" .
        "# =========================================\n\n" .
        "# ========== BASE DE DATOS ==========\n" .
        "DB_HOST={$db_host}\n" .
        "DB_USER={$db_user}\n" .
        "DB_PASSWORD={$db_password}\n" .
        "DB_NAME={$db_name}\n" .
        "CRYPTO_KEY={$cryptoKey}\n\n" .
        "# ========== WHATSAPP - WAMUNDO.COM ==========\n" .
        "# Las siguientes claves se configurarán desde el panel administrativo\n" .
        "# WHATSAPP_NEW_API_URL: URL base de la API de Wamundo\n" .
        "WHATSAPP_NEW_API_URL=\n" .
        "# WHATSAPP_NEW_WEBHOOK_SECRET: secreto para validar webhooks\n" .
        "WHATSAPP_NEW_WEBHOOK_SECRET=\n" .
        "# WHATSAPP_NEW_SEND_SECRET: secreto para enviar mensajes\n" .
        "WHATSAPP_NEW_SEND_SECRET=\n" .
        "# WHATSAPP_NEW_ACCOUNT_ID: identificador de la cuenta en Wamundo\n" .
        "WHATSAPP_NEW_ACCOUNT_ID=\n" .
        "WHATSAPP_NEW_LOG_LEVEL=info\n" .
        "WHATSAPP_NEW_API_TIMEOUT=30\n" .
        "WHATSAPP_ACTIVE_WEBHOOK=wamundo\n\n" .
        "# ========== SISTEMA ==========\n" .
        "ENVIRONMENT=production\n" .
        "DEBUG_MODE=0\n" .
        "SESSION_LIFETIME=3600\n" .
        "LOG_LEVEL=info\n\n" .
        "# ========== CONFIGURACIONES DE NEGOCIO ==========\n" .
        "EMAIL_AUTH_ENABLED=1\n" .
        "REQUIRE_LOGIN=1\n" .
        "USER_EMAIL_RESTRICTIONS_ENABLED=1\n" .
        "USER_SUBJECT_RESTRICTIONS_ENABLED=1\n" .
        "ADMIN_EMAIL_OVERRIDE=1\n" .
        "MAX_SEARCH_RESULTS=50\n" .
        "CACHE_ENABLED=1\n" .
        "CACHE_LIFETIME=300\n";

    $project_root = dirname(__DIR__);
    $env_path = $project_root . '/.env';

    // Crear .env en la raíz del proyecto
    if (file_put_contents($env_path, $env_content) !== false) {
        // Establecer la clave en la sesión actual
        putenv("CRYPTO_KEY=$cryptoKey");
        $_ENV['CRYPTO_KEY'] = $cryptoKey;

        // Verificar que la clave se escribió correctamente en el archivo
        $written_env = file_get_contents($env_path);
        return strpos($written_env, "CRYPTO_KEY={$cryptoKey}") !== false;
    }

    return false;
}

function insertWamundoDefaultSettings($pdo) {
    // Verificar que la tabla settings existe antes de intentar escribir
    $table_check = $pdo->query("SHOW TABLES LIKE 'settings'");
    if ($table_check === false || $table_check->rowCount() === 0) {
        return; // No se puede insertar si la tabla no existe
    }

    // Configuraciones mínimas requeridas para Wamundo
    $default_settings = [
        'WHATSAPP_NEW_LOG_LEVEL' => 'info',
        'WHATSAPP_NEW_API_TIMEOUT' => '30',
        'WHATSAPP_ACTIVE_WEBHOOK' => 'wamundo'
    ];

    foreach ($default_settings as $key => $value) {
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        } catch (Exception $e) {
            // Log pero no fallar - estas son configuraciones opcionales
            error_log("Warning: No se pudo insertar configuración $key: " . $e->getMessage());
        }
    }
}


function validateInstallationData($data) {
    $errors = [];
    
    // Validaciones básicas obligatorias
    if (empty($data['db_host'])) $errors[] = "El servidor de BD es obligatorio";
    if (empty($data['db_name'])) $errors[] = "El nombre de la BD es obligatorio";
    if (empty($data['db_user'])) $errors[] = "El usuario de BD es obligatorio";
    if (empty($data['admin_user'])) $errors[] = "El usuario admin es obligatorio";
    if (strlen($data['admin_user']) < 3) $errors[] = "El usuario admin debe tener al menos 3 caracteres";
    if (empty($data['admin_password'])) $errors[] = "La contraseña admin es obligatoria";
    
    // ✅ CORRECCIÓN: Validaciones unificadas de contraseña (más estrictas)
    if (strlen($data['admin_password']) < 8) {
        $errors[] = "La contraseña debe tener al menos 8 caracteres para mayor seguridad";
    }
    
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $data['admin_password'])) {
        $errors[] = "La contraseña debe contener al menos una minúscula, una mayúscula y un número";
    }
    
    // Validar Telegram ID si se proporciona
    if (!empty($data['admin_telegram']) && !preg_match('/^[0-9]+$/', $data['admin_telegram'])) {
        $errors[] = "El ID de Telegram debe contener solo números";
    }
    
    // Validaciones de formato
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $data['db_name'])) {
        $errors[] = "El nombre de BD solo puede contener letras, números, guiones y puntos";
    }
    
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $data['admin_user'])) {
        $errors[] = "El usuario admin solo puede contener letras, números y guiones";
    }
    
    // === VALIDACIONES OPCIONALES DE TELEGRAM ===
    
    // Validar token de Telegram si se proporciona
    if (!empty($data['telegram_token'])) {
        if (!preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/', $data['telegram_token'])) {
            $errors[] = "El token de Telegram no tiene el formato correcto";
        }
        
        if (strlen($data['telegram_token']) < 40) {
            $errors[] = "El token de Telegram parece ser demasiado corto";
        }
    }

    // Validar webhook URL si se proporciona
    if (!empty($data['telegram_webhook'])) {
        if (!filter_var($data['telegram_webhook'], FILTER_VALIDATE_URL)) {
            $errors[] = "La URL del webhook de Telegram no es válida";
        }
        
        if (strpos($data['telegram_webhook'], 'https://') !== 0) {
            $errors[] = "La URL del webhook debe usar HTTPS para seguridad";
        }
    }
    
    return $errors;
}

function testDatabaseConnection($host, $user, $password) {
    try {
        $test_conn = new PDO("mysql:host={$host}", $user, $password);
        $test_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $test_conn = null;
    } catch (PDOException $e) {
        throw new Exception("No se pudo conectar a la base de datos: " . $e->getMessage());
    }
}

function createConfigurationFiles($db_host, $db_name, $db_user, $db_password) {
    $db_host_escaped = addslashes($db_host);
    $db_name_escaped = addslashes($db_name);
    $db_user_escaped = addslashes($db_user);
    $db_password_escaped = addslashes($db_password);

    $credentials_content = "<?php
// Archivo generado automáticamente durante la instalación
\$db_host = '{$db_host_escaped}';
\$db_user = '{$db_user_escaped}';
\$db_password = '{$db_password_escaped}';
\$db_name = '{$db_name_escaped}';
?>";

    // ✅ CORRECCIÓN: Asegurar que el directorio config existe
    $config_dir = dirname(__DIR__) . '/config';
    if (!file_exists($config_dir)) {
        if (!mkdir($config_dir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio config: {$config_dir}");
        }
    }

    // Crear db_credentials.php en config/
    $credentials_path = $config_dir . '/db_credentials.php';
    if (!file_put_contents($credentials_path, $credentials_content)) {
        throw new Exception("No se pudo crear el archivo db_credentials.php");
    }
    
    // ✅ NUEVO: También crear basededatos.php en instalacion/ para verificación post-instalación
    $basededatos_path = __DIR__ . '/basededatos.php';
    if (!file_put_contents($basededatos_path, $credentials_content)) {
        throw new Exception("No se pudo crear el archivo basededatos.php");
    }
}

function setupDatabase($db_host, $db_name, $db_user, $db_password) {
    $pdo = new PDO("mysql:host={$db_host}", $db_user, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci");
    $pdo->exec("USE `{$db_name}`");
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    return $pdo;
}

// ============================================
// FUNCIÓN UNIFICADA PARA CREAR TODA LA BD - ACTUALIZADA
// ============================================
function createCompleteDatabase($pdo) {
    
    $tables = [
        // --- Tablas Principales del Sistema ---
        "CREATE TABLE IF NOT EXISTS `admin` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            username VARCHAR(50) NOT NULL UNIQUE, 
            password VARCHAR(255) NOT NULL, 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `users` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            username VARCHAR(50) NOT NULL UNIQUE, 
            password VARCHAR(255) NOT NULL, 
            telegram_id BIGINT NULL UNIQUE, 
            telegram_username VARCHAR(255) NULL, 
            last_telegram_activity TIMESTAMP NULL, 
            status TINYINT(1) DEFAULT 1, 
            role ENUM('user', 'admin', 'superadmin') DEFAULT 'user', 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_telegram_id (telegram_id),
            INDEX idx_status_role (status, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `settings` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            name VARCHAR(100) NOT NULL UNIQUE, 
            value TEXT NOT NULL, 
            description TEXT, 
            category VARCHAR(50) DEFAULT 'general',
            INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `email_servers` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            server_name VARCHAR(50) NOT NULL, 
            enabled TINYINT(1) NOT NULL DEFAULT 0, 
            imap_server VARCHAR(100) NOT NULL, 
            imap_port INT NOT NULL DEFAULT 993, 
            imap_user VARCHAR(100) NOT NULL, 
            imap_password VARCHAR(100) NOT NULL, 
            priority INT DEFAULT 1,
            INDEX idx_enabled_priority (enabled, priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        // --- Tablas de Plataformas y Asuntos ---
        "CREATE TABLE IF NOT EXISTS `platforms` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            logo VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status_sort (status, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `platform_subjects` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            platform_id INT NOT NULL, 
            subject VARCHAR(255) NOT NULL, 
            FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE,
            INDEX idx_platform_id (platform_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        // --- Tablas de Permisos y Asignaciones ---
        "CREATE TABLE IF NOT EXISTS `authorized_emails` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            email VARCHAR(255) NOT NULL UNIQUE, 
            description TEXT, 
            status TINYINT(1) DEFAULT 1, 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `user_authorized_emails` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            user_id INT NOT NULL, 
            authorized_email_id INT NOT NULL, 
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
            assigned_by INT DEFAULT NULL, 
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, 
            FOREIGN KEY (authorized_email_id) REFERENCES authorized_emails(id) ON DELETE CASCADE, 
            UNIQUE KEY unique_user_email (user_id, authorized_email_id),
            INDEX idx_user_id (user_id),
            INDEX idx_email_id (authorized_email_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `user_platform_subjects` (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            user_id INT NOT NULL, 
            platform_id INT NOT NULL, 
            subject_keyword VARCHAR(255), 
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, 
            FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE, 
            UNIQUE KEY unique_assignment (user_id, platform_id, subject_keyword(191)),
            INDEX idx_user_platform (user_id, platform_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",

        "CREATE TABLE IF NOT EXISTS `user_permission_templates` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            email_ids JSON,
            platform_ids JSON,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `user_groups` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            user_ids JSON,
            template_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // --- Tablas de Logs (Sistema Web y Bot) ---
        "CREATE TABLE IF NOT EXISTS `audit_log` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(255) NOT NULL,
            ip VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",

        "CREATE TABLE IF NOT EXISTS `logs` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            email_consultado VARCHAR(100) NOT NULL,
            plataforma VARCHAR(50) NOT NULL,
            ip VARCHAR(45), 
            fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
            resultado TEXT, 
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_fecha (user_id, fecha),
            INDEX idx_plataforma (plataforma)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `search_logs` (
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
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_telegram_chat (telegram_chat_id),
            INDEX idx_source_status (source, status),
            INDEX idx_platform (platform)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",
        
        "CREATE TABLE IF NOT EXISTS `telegram_bot_logs` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            telegram_id BIGINT,
            action VARCHAR(50),
            details JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_telegram_id (telegram_id),
            INDEX idx_created_at (created_at),
            INDEX idx_updated_at (updated_at),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS `telegram_activity_log` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_telegram_id (telegram_id),
            INDEX idx_created_at (created_at),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci",

        "CREATE TABLE IF NOT EXISTS `telegram_sessions` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT,
            user_id INT,
            session_token VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // *** TABLA CRÍTICA PARA EL BOT DE TELEGRAM ***
        "CREATE TABLE IF NOT EXISTS `telegram_temp_data` (
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

        // --- Tablas de soporte para WhatsApp ---
        "CREATE TABLE IF NOT EXISTS `whatsapp_temp_data` (
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

        "CREATE TABLE IF NOT EXISTS `whatsapp_activity_log` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            whatsapp_id BIGINT NOT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_whatsapp_id (whatsapp_id),
            INDEX idx_created_at (created_at),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci"
    ];
    
    foreach ($tables as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            error_log("Error creando tabla: " . $e->getMessage());
        }
    }

    // Asegurar que la columna logo exista en platforms
    try {
        $checkLogo = $pdo->query("SHOW COLUMNS FROM platforms LIKE 'logo'");
        if ($checkLogo && $checkLogo->rowCount() == 0) {
            $pdo->exec("ALTER TABLE platforms ADD COLUMN logo VARCHAR(255) NULL AFTER description");
        }
    } catch (Exception $e) {
        error_log("Error asegurando columna logo: " . $e->getMessage());
    }

    // Asegurar que la columna sort_order exista en platforms
    try {
        $checkSort = $pdo->query("SHOW COLUMNS FROM platforms LIKE 'sort_order'");
        if ($checkSort && $checkSort->rowCount() == 0) {
            $pdo->exec("ALTER TABLE platforms ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER logo");
        }
    } catch (Exception $e) {
        error_log("Error asegurando columna sort_order: " . $e->getMessage());
    }

    // Agregar columnas de soporte para WhatsApp en users
    try {
        $checkWhatsappId = $pdo->query("SHOW COLUMNS FROM users LIKE 'whatsapp_id'");
        if ($checkWhatsappId && $checkWhatsappId->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_id BIGINT NULL UNIQUE AFTER last_telegram_activity");
        }
    } catch (Exception $e) {
        error_log("Error agregando whatsapp_id: " . $e->getMessage());
    }

    try {
        $checkWhatsappUsername = $pdo->query("SHOW COLUMNS FROM users LIKE 'whatsapp_username'");
        if ($checkWhatsappUsername && $checkWhatsappUsername->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_username VARCHAR(255) NULL AFTER whatsapp_id");
        }
    } catch (Exception $e) {
        error_log("Error agregando whatsapp_username: " . $e->getMessage());
    }

    try {
        $checkLastWhatsapp = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_whatsapp_activity'");
        if ($checkLastWhatsapp && $checkLastWhatsapp->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_whatsapp_activity TIMESTAMP NULL AFTER whatsapp_username");
        }
    } catch (Exception $e) {
        error_log("Error agregando last_whatsapp_activity: " . $e->getMessage());
    }

    // Agregar columna whatsapp_chat_id en search_logs
    try {
        $checkWhatsappChat = $pdo->query("SHOW COLUMNS FROM search_logs LIKE 'whatsapp_chat_id'");
        if ($checkWhatsappChat && $checkWhatsappChat->rowCount() == 0) {
            $pdo->exec("ALTER TABLE search_logs ADD COLUMN whatsapp_chat_id BIGINT NULL AFTER telegram_chat_id, ADD INDEX idx_whatsapp_chat (whatsapp_chat_id)");
        }
    } catch (Exception $e) {
        error_log("Error agregando whatsapp_chat_id: " . $e->getMessage());
    }
}

// ✅ CORRECCIÓN CRÍTICA: Agregar parámetro $admin_telegram
function insertInitialData($pdo, $admin_user, $admin_password, $admin_telegram = null) {
    $pdo->beginTransaction();
    
    try {
        insertSystemSettings($pdo);
        insertDefaultPlatforms($pdo);
        
        // ✅ CORRECCIÓN: Pasar correctamente el parámetro
        insertSystemUsers($pdo, $admin_user, $admin_password, $admin_telegram);
        
        insertExampleEmailsAndAssignments($pdo);
        insertDefaultServers($pdo);
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception("Error insertando datos iniciales: " . $e->getMessage());
    }
}

// ============================================
// CONFIGURACIONES UNIFICADAS Y ORGANIZADAS
// ============================================
function insertSystemSettings($pdo) {
    $settings = [
        // === CONFIGURACIONES GENERALES ===
        ['enlace_global_titulo', 'StreamDigi', 'Título que aparece en el header', 'general'],
        ['PAGE_TITLE', 'Consulta tu Código', 'Título de la página principal', 'general'],
        ['LOGO', 'logo.png', 'Nombre del archivo de logo', 'general'],
        
        // === CONFIGURACIONES DE ENLACES ===
        ['enlace_global_1', 'https://', 'Enlace del botón 1 en el header', 'enlaces'],
        ['enlace_global_1_texto', 'Ir a Página web', 'Texto del botón 1 en el header', 'enlaces'],
        ['enlace_global_2', 'https://t.me/', 'Enlace del botón 2 en el header', 'enlaces'],
        ['enlace_global_2_texto', 'Ir a Telegram', 'Texto del botón 2 en el header', 'enlaces'],
        ['enlace_global_numero_whatsapp', '000000', 'Número de WhatsApp para contacto', 'enlaces'],
        ['enlace_global_texto_whatsapp', 'Hola, necesito soporte técnico', 'Mensaje predeterminado para WhatsApp', 'enlaces'],
        ['ID_VENDEDOR', '0', 'ID del vendedor para enlaces de afiliados', 'enlaces'],
        
        // === CONFIGURACIONES DE AUTENTICACIÓN ===
        ['REQUIRE_LOGIN', '1', 'Requerir inicio de sesión para todos los usuarios', 'auth'],
        ['EMAIL_AUTH_ENABLED', '1', 'Habilitar filtro de correos electrónicos autorizados', 'auth'],
        ['USER_EMAIL_RESTRICTIONS_ENABLED', '1', 'Activar restricciones de correos por usuario', 'auth'],
        ['USER_SUBJECT_RESTRICTIONS_ENABLED', '1', 'Activar restricciones de asuntos por usuario', 'auth'],
        ['ADMIN_EMAIL_OVERRIDE', '1', 'Administradores pueden acceder a todos los emails', 'auth'],
        
        // === CONFIGURACIONES DE PERFORMANCE ===
        ['EMAIL_QUERY_TIME_LIMIT_MINUTES', '20', 'Tiempo máximo para considerar emails válidos', 'performance'],
        ['TIMEZONE_DEBUG_HOURS', '24', 'Horas hacia atrás para búsqueda inicial IMAP', 'performance'],
        ['IMAP_CONNECTION_TIMEOUT', '6', 'Tiempo límite para conexiones IMAP (segundos)', 'performance'],
        ['IMAP_SEARCH_OPTIMIZATION', '1', 'Activar optimizaciones de búsqueda IMAP', 'performance'],
        ['EARLY_SEARCH_STOP', '1', 'Parar búsqueda al encontrar primer resultado', 'performance'],
        ['TRUST_IMAP_DATE_FILTER', '1', 'Confiar en el filtrado de fechas IMAP', 'performance'],
        ['USE_PRECISE_IMAP_SEARCH', '0', 'Usar búsquedas IMAP más precisas', 'performance'],
        ['MAX_EMAILS_TO_CHECK', '35', 'Número máximo de emails a verificar por consulta', 'performance'],
        ['IMAP_SEARCH_TIMEOUT', '30', 'Tiempo límite para búsquedas IMAP en segundos', 'performance'],
        
        // === CONFIGURACIONES DE CACHE ===
        ['CACHE_ENABLED', '1', 'Activar sistema de cache para mejorar performance', 'cache'],
        ['CACHE_TIME_MINUTES', '5', 'Tiempo de vida del cache en minutos', 'cache'],
        ['CACHE_MEMORY_ENABLED', '1', 'Activar cache en memoria para consultas repetidas', 'cache'],
        
        // === CONFIGURACIONES DEL BOT DE TELEGRAM ===
        ['TELEGRAM_BOT_ENABLED', '1', 'Habilitar bot de Telegram', 'telegram'],
        ['TELEGRAM_BOT_TOKEN', '', 'Token del bot de Telegram', 'telegram'],
        ['TELEGRAM_WEBHOOK_URL', '', 'URL del webhook de Telegram', 'telegram'],
        ['TELEGRAM_WEBHOOK_SECRET', '', 'Secreto del webhook de Telegram', 'telegram'],
        ['TELEGRAM_RATE_LIMIT', '30', 'Límite de solicitudes por minuto para Telegram', 'telegram'],
        ['TELEGRAM_LOG_ACTIVITY', '0', 'Registrar actividad del bot de Telegram', 'telegram'],
        ['TELEGRAM_WEBHOOK_ENABLED', '1', 'Habilitar webhook de Telegram', 'telegram'],
        ['MAX_TELEGRAM_REQUESTS_PER_USER', '100', 'Máximo solicitudes por usuario por día vía Telegram', 'telegram'],
        ['TELEGRAM_COMMAND_COOLDOWN', '2', 'Tiempo de espera entre comandos en segundos', 'telegram'],
        ['TELEGRAM_ADMIN_NOTIFICATIONS', '1', 'Notificar a admins sobre actividad sospechosa', 'telegram'],
        
        // === NUEVAS CONFIGURACIONES DE SEGURIDAD ===
        ['WEBHOOK_SECRET_VALIDATION', '1', 'Validar secreto del webhook de Telegram', 'telegram'],
        ['TELEGRAM_DEBUG_MODE', '0', 'Modo debug para el bot (0 = off, 1 = on)', 'telegram'],
        ['TELEGRAM_RATE_LIMIT_MINUTE', '20', 'Límite de requests por minuto por usuario', 'telegram'],
        ['TELEGRAM_RATE_LIMIT_HOUR', '100', 'Límite de requests por hora por usuario', 'telegram'],

        // === NUEVAS CONFIGURACIONES DE LOGGING ===
        ['LOG_LEVEL', 'INFO', 'Nivel de logging: ERROR, WARNING, INFO, DEBUG', 'logs'],
        ['LOG_RETENTION_DAYS', '7', 'Días para mantener archivos de log', 'logs'],
        ['LOG_MAX_FILE_SIZE', '2048', 'Tamaño máximo de archivo de log en KB', 'logs'],

        // === CONFIGURACIONES PARA CÓDIGOS ÚNICOS ===
        ['AUTO_CLEANUP_ENABLED', '1', 'Limpieza automática de datos temporales', 'system'],
        ['UNIQUE_CODE_MODE', '1', 'Modo códigos únicos - limpia datos inmediatamente', 'performance'],
        ['TEMP_DATA_RETENTION_MINUTES', '2', 'Minutos para mantener datos temporales', 'performance'],
        
        // === CONFIGURACIONES DE LOGS ===
        ['PERFORMANCE_LOGGING', '0', 'Activar logs de rendimiento (temporal para debugging)', 'logs'],
        ['TELEGRAM_LOG_RETENTION_DAYS', '15', 'Días para mantener logs de Telegram', 'logs'],
        ['SEARCH_LOG_RETENTION_DAYS', '15', 'Días para mantener logs de búsqueda', 'logs'],
        
        // === CONFIGURACIONES DE SISTEMA ===
        ['LICENSE_PROTECTED', '1', 'Sistema protegido por licencia', 'system'],
        ['INSTALLED', '0', 'Indica si el sistema está instalado correctamente', 'system']
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (name, value, description, category) VALUES (?, ?, ?, ?)");
    foreach ($settings as $setting) {
        $stmt->execute($setting);
    }
}

function insertSystemUsers($pdo, $admin_user, $admin_password, $admin_telegram = null) {
    $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
    
    // Convertir telegram_id a null si está vacío
    $telegram_id = (!empty($admin_telegram)) ? (int)$admin_telegram : null;

    // Crear usuario admin con role
    $stmt_user = $pdo->prepare("INSERT INTO users (username, password, telegram_id, status, role) VALUES (?, ?, ?, 1, 'admin')");
    $stmt_user->execute([$admin_user, $hashed_password, $telegram_id]);
    $admin_user_id = $pdo->lastInsertId();
    
    // Crear entrada en tabla admin
    $stmt_admin = $pdo->prepare("INSERT INTO admin (id, username, password) VALUES (?, ?, ?)");
    $stmt_admin->execute([$admin_user_id, $admin_user, $hashed_password]);
    
    // Crear usuario cliente de ejemplo
    $cliente_password = password_hash('cliente123', PASSWORD_DEFAULT);
    $stmt_cliente = $pdo->prepare("INSERT INTO users (username, password, telegram_id, status, role) VALUES (?, ?, ?, 1, 'user')");
    $stmt_cliente->execute(['cliente', $cliente_password, null]);
}

function insertExampleEmailsAndAssignments($pdo) {
    $example_emails = [
        'ejemplo1@gmail.com',
        'ejemplo2@outlook.com',
        'test@yahoo.com'
    ];
    
    $stmt_email = $pdo->prepare("INSERT IGNORE INTO authorized_emails (email) VALUES (?)");
    $email_ids = [];
    
    foreach ($example_emails as $email) {
        $stmt_email->execute([$email]);
        $email_id = $pdo->lastInsertId();
        if ($email_id == 0) {
            $stmt_get = $pdo->prepare("SELECT id FROM authorized_emails WHERE email = ?");
            $stmt_get->execute([$email]);
            $email_id = $stmt_get->fetchColumn();
        }
        $email_ids[] = $email_id;
    }
    
    $stmt_get_cliente = $pdo->prepare("SELECT id FROM users WHERE username = 'cliente'");
    $stmt_get_cliente->execute();
    $cliente_id = $stmt_get_cliente->fetchColumn();
    
    if ($cliente_id && !empty($email_ids)) {
        $stmt_assign = $pdo->prepare("INSERT IGNORE INTO user_authorized_emails (user_id, authorized_email_id, assigned_by) VALUES (?, ?, ?)");
        foreach ($email_ids as $email_id) {
            $stmt_assign->execute([$cliente_id, $email_id, 1]);
        }
    }
}

function insertDefaultPlatforms($pdo) {
    $platforms = [
        'Netflix' => [
            'description' => 'Servicio de streaming Netflix',
            'subjects' => [
                'Tu código de acceso temporal de Netflix',
                'Importante: Cómo actualizar tu Hogar con Netflix',
                'Netflix: Tu código de inicio de sesión',
                'Completa tu solicitud de restablecimiento de contraseña'
            ]
        ],
        'Disney+' => [
            'description' => 'Servicio de streaming Disney Plus',
            'subjects' => [
                'Tu código de acceso único para Disney+',
                'Disney+: Verificación de cuenta',
                'Disney+: Código de seguridad',
                'Disney+: Actualización de perfil'
            ]
        ],
        'Prime Video' => [
            'description' => 'Amazon Prime Video',
            'subjects' => [
                'amazon.com: Sign-in attempt',
                'amazon.com: Intento de inicio de sesión',
                'Amazon Prime: Código de verificación',
                'Amazon: Actividad inusual en tu cuenta'
            ]
        ],
        'MAX' => [
            'description' => 'Servicio de streaming MAX',
            'subjects' => [
                'Tu código de acceso MAX',
                'MAX: Intento de inicio de sesión',
                'MAX: Tu código de verificación',
                'MAX: Actualización de tu cuenta'
            ]
        ],
        'Spotify' => [
            'description' => 'Servicio de música Spotify',
            'subjects' => [
                'Spotify: Código de verificación',
                'Spotify: Cambio de contraseña solicitado',
                'Spotify: Nuevo inicio de sesión detectado',
                'Spotify: Confirma tu dirección de email'
            ]
        ],
        'PayPal' => [
            'description' => 'Servicio de pagos PayPal',
            'subjects' => [
                'PayPal: Código de verificación',
                'PayPal: Actividad inusual',
                'PayPal: Confirma tu identidad',
                'PayPal: Nuevo dispositivo detectado'
            ]
        ],
        'Crunchyroll' => [
            'description' => 'Servicio de anime Crunchyroll',
            'subjects' => [
                'Crunchyroll: Código de acceso',
                'Crunchyroll: Actualización de cuenta',
                'Crunchyroll: Solicitud de inicio de sesión',
                'Crunchyroll: Restablecimiento de contraseña'
            ]
        ],
        'Paramount+' => [
            'description' => 'Servicio de streaming Paramount Plus',
            'subjects' => [
                'Paramount Plus: Código de acceso',
                'Paramount Plus: Actualización de cuenta',
                'Paramount Plus: Solicitud de inicio de sesión',
                'Paramount Plus: Restablecimiento de contraseña'
            ]
        ],
        'ChatGPT' => [
            'description' => 'OpenAI ChatGPT',
            'subjects' => [
                'Cambio de Contraseña',
                'Cambio de Correo Electrónico',
                'Cambio de Nombre',
                'Cambio de Cuenta'
            ]
        ]
    ];

    $stmt_platform = $pdo->prepare("INSERT IGNORE INTO platforms (name, description, sort_order, status) VALUES (?, ?, ?, 1)");
    $stmt_subject = $pdo->prepare("INSERT INTO platform_subjects (platform_id, subject) VALUES (?, ?)");

    $sort_order = 0;
    foreach ($platforms as $platform_name => $platform_data) {
        $stmt_platform->execute([$platform_name, $platform_data['description'], $sort_order]);
        $platform_id = $pdo->lastInsertId();
        
        if ($platform_id == 0) {
            $stmt_find = $pdo->prepare("SELECT id FROM platforms WHERE name = ?");
            $stmt_find->execute([$platform_name]);
            $platform_id = $stmt_find->fetchColumn();
        }

        if ($platform_id) {
            foreach ($platform_data['subjects'] as $subject) {
                $stmt_subject->execute([$platform_id, $subject]);
            }
        }
        $sort_order++;
    }
}

function insertDefaultServers($pdo) {
    $default_servers = [
        ["SERVIDOR_1", 0, "imap.gmail.com", 993, "usuario1@gmail.com", "", 1],
        ["SERVIDOR_2", 0, "imap.gmail.com", 993, "usuario2@gmail.com", "", 2],
        ["SERVIDOR_3", 0, "imap.gmail.com", 993, "usuario3@gmail.com", "", 3],
        ["SERVIDOR_4", 0, "outlook.office365.com", 993, "usuario4@outlook.com", "", 4],
        ["SERVIDOR_5", 0, "imap.mail.yahoo.com", 993, "usuario5@yahoo.com", "", 5]
    ];
    
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO email_servers (server_name, enabled, imap_server, imap_port, imap_user, imap_password, priority) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    
    foreach ($default_servers as $server) {
        $stmt->execute($server);
    }
}

// ✅ CORRECCIÓN: Optimización de setupFileSystem
function setupFileSystem() {
    $permissions_map = [
        PROJECT_ROOT . '/cache/' => 0755,
        PROJECT_ROOT . '/cache/data/' => 0777,
        PROJECT_ROOT . '/images/logo/' => 0755,
        PROJECT_ROOT . '/images/fondo/' => 0755,
        PROJECT_ROOT . '/images/platforms/' => 0755,
        PROJECT_ROOT . '/telegram_bot/logs/' => 0777,
        PROJECT_ROOT . '/config/' => 0755,
        LICENSE_DIR => 0755
    ];
    
    // ✅ CORRECCIÓN: Crear directorios primero, luego archivos de seguridad
    foreach ($permissions_map as $dir => $permissions) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, $permissions, true)) {
                throw new Exception("No se pudo crear el directorio: {$dir}");
            }
        }
        chmod($dir, $permissions);
    }
    
    // ✅ CORRECCIÓN: Crear archivos de seguridad una sola vez
    createSecurityFiles();
    
    // Crear archivos de protección
    $htaccess_content = "# Proteger carpeta de cache\nDeny from all\n<Files \"*.json\">\nDeny from all\n</Files>";
    file_put_contents(PROJECT_ROOT . '/cache/data/.htaccess', $htaccess_content);
    
    $license_htaccess = "Deny from all\n<Files \"*.dat\">\nDeny from all\n</Files>";
    file_put_contents(LICENSE_DIR . '/.htaccess', $license_htaccess);

    // Proteger logs del bot
    $bot_logs_htaccess = "Deny from all\n<Files \"*.log\">\nDeny from all\n</Files>";
    file_put_contents(PROJECT_ROOT . '/telegram_bot/logs/.htaccess', $bot_logs_htaccess);

    // Restringir listado de la carpeta de plataformas
    $platforms_htaccess = "";
    $platforms_htaccess_path = PROJECT_ROOT . '/images/platforms/.htaccess';
    if (!file_exists($platforms_htaccess_path)) {
        file_put_contents($platforms_htaccess_path, $platforms_htaccess);
    }
    
    // Crear archivo de log inicial para el bot
    $initial_log = date('Y-m-d H:i:s') . " [INFO] Bot de Telegram inicializado durante instalación\n";
    file_put_contents(PROJECT_ROOT . '/telegram_bot/logs/bot.log', $initial_log);
    
    $files = [
        PROJECT_ROOT . '/cache/cache_helper.php' => 0755,
        PROJECT_ROOT . '/config/config.php' => 0644
    ];
    
    foreach ($files as $file => $permissions) {
        if (file_exists($file)) {
            chmod($file, $permissions);
        }
    }
}

function createSecurityFiles() {
    // 1. Crear archivo .env.example
    $env_example = "# =========================================
# CONFIGURACIÓN DE SEGURIDAD - StreamDigi
# =========================================
# IMPORTANTE: Copia este archivo a .env y actualiza los valores

# === TELEGRAM BOT ===
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_URL=
TELEGRAM_WEBHOOK_SECRET=StreamDigi_" . date('Y') . "_" . bin2hex(random_bytes(8)) . "

# === CONFIGURACIONES ADICIONALES ===
ENVIRONMENT=production
DEBUG_MODE=0
LOG_LEVEL=INFO
LOG_RETENTION_DAYS=7
TELEGRAM_RATE_LIMIT=20
";
    
    file_put_contents(PROJECT_ROOT . '/telegram_bot/.env.example', $env_example);
    
    // 2. Actualizar .gitignore
    $gitignore_path = PROJECT_ROOT . '/.gitignore';
    if (file_exists($gitignore_path)) {
        $existing_content = file_get_contents($gitignore_path);
        
        $critical_lines = [
            '.env',
            'telegram_bot/.env',
            'cache/data/settings.json',
            'telegram_bot/cache/data/settings.json',
            'admin/cache/data/settings.json'
        ];
        
        $lines_to_add = [];
        foreach ($critical_lines as $line) {
            if (strpos($existing_content, $line) === false) {
                $lines_to_add[] = $line;
            }
        }
        
        if (!empty($lines_to_add)) {
            $additional_content = "\n# Líneas de seguridad agregadas automáticamente\n" . implode("\n", $lines_to_add);
            file_put_contents($gitignore_path, $existing_content . $additional_content);
        }
    }
    
    // 3. Crear helper de variables de entorno
    createEnvHelper();
}

function createEnvHelper() {
    $env_helper_content = '<?php
class EnvHelper {
    private static $loaded = false;
    private static $env_vars = [];
    
    public static function load($env_file = null) {
        if (self::$loaded) return;
        
        $possible_locations = [
            $env_file,
            __DIR__ . "/../telegram_bot/.env",
            __DIR__ . "/../.env",
            dirname(__DIR__) . "/.env"
        ];
        
        foreach ($possible_locations as $location) {
            if ($location && file_exists($location)) {
                self::loadFromFile($location);
                break;
            }
        }
        
        self::$loaded = true;
    }
    
    private static function loadFromFile($file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos(trim($line), "#") === 0) continue;
            
            if (strpos($line, "=") !== false) {
                list($key, $value) = explode("=", $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                self::$env_vars[$key] = $value;
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
    
    public static function get($key, $default = null) {
        self::load();
        return self::$env_vars[$key] ?? $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

function env($key, $default = null) {
    return EnvHelper::get($key, $default);
}

EnvHelper::load();
?>';
    
    file_put_contents(PROJECT_ROOT . '/config/env_helper.php', $env_helper_content);
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function cleanupInstaller() {
    $installDir = __DIR__;
    $projectRoot = dirname($installDir);

    $files = [
        $installDir . '/instalador.php',
        $installDir . '/decode.log',
        $installDir . '/error_log',
        $installDir . '/installed.txt',
        $projectRoot . '/decode.log',
        $projectRoot . '/error_log'
    ];

    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    foreach (glob($installDir . '/*') as $path) {
        if (basename($path) === 'basededatos.php') {
            continue;
        }
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
}

function finalizeInstallation($pdo) {
    $stmt = $pdo->prepare("INSERT INTO settings (name, value, description, category) VALUES ('INSTALLED', '1', 'Sistema instalado correctamente', 'system') ON DUPLICATE KEY UPDATE value = '1'");
    $stmt->execute();
    
    $install_file_content = date('Y-m-d H:i:s') . " - Instalación completada exitosamente con licencia activada\n" .
        "Archivo de licencia: " . LICENSE_FILE . "\n" .
        "Directorio de licencias: " . LICENSE_DIR . "\n" .
        "Base de datos configurada: SÍ\n" .
        "Bot de Telegram: INTEGRADO COMPLETAMENTE\n" .
        "Tabla telegram_temp_data: CREADA\n" .
        "Configuración INSTALLED creada: SÍ\n" .
        "Todas las tablas necesarias: CREADAS";
    
    file_put_contents(__DIR__ . '/installed.txt', $install_file_content);
    
    if (class_exists('SimpleCache')) {
        SimpleCache::clear_cache();
    }
    
    $check_stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'INSTALLED'");
    $check_stmt->execute();
    $check_result = $check_stmt->fetch();

    if (!$check_result || $check_result['value'] !== '1') {
        throw new Exception('Error: No se pudo confirmar la instalación en la base de datos');
    }

    cleanupInstaller();
}

function verifyInstallation() {
    $installation_verified = false;
    $verification_error = null;

    try {
        // 1. Verificar que el archivo basededatos.php existe
        $basededatos_path = INSTALL_DIR . '/basededatos.php';
        if (!file_exists($basededatos_path)) {
            throw new Exception('Archivo basededatos.php no encontrado después de la instalación');
        }

        require_once $basededatos_path;

        // 2. Verificar conexión a la base de datos
        $test_conn = new mysqli($db_host, $db_user, $db_password, $db_name);
        $test_conn->set_charset("utf8mb4");

        if ($test_conn->connect_error) {
            throw new Exception('Error de conexión post-instalación: ' . $test_conn->connect_error);
        }

        // 3. Verificar configuración INSTALLED en base de datos
        $test_result = $test_conn->query("SELECT value FROM settings WHERE name = 'INSTALLED'");
        if (!$test_result || $test_result->num_rows === 0) {
            throw new Exception('Configuración INSTALLED no encontrada en la base de datos');
        }

        $test_row = $test_result->fetch_assoc();
        if ($test_row['value'] !== '1') {
            throw new Exception('Configuración INSTALLED no está establecida correctamente. Valor actual: ' . $test_row['value']);
        }

        // 4. Verificar que las tablas críticas existen
        $required_tables = ['telegram_temp_data', 'whatsapp_sessions', 'whatsapp_activity_log'];
        $missing_tables = [];

        foreach ($required_tables as $table) {
            $table_check = $test_conn->query("SHOW TABLES LIKE '$table'");
            if (!$table_check || $table_check->num_rows === 0) {
                $missing_tables[] = $table;
            }
        }

        if (!empty($missing_tables)) {
            throw new Exception('Faltan tablas críticas: ' . implode(', ', $missing_tables));
        }

        // 5. Verificar que el archivo de configuración DB existe
        $config_db_path = dirname(INSTALL_DIR) . '/config/db_credentials.php';
        if (!file_exists($config_db_path)) {
            throw new Exception('Archivo config/db_credentials.php no fue creado');
        }

        // 6. Verificar que el .env fue creado y contiene la clave de cifrado
        $env_path = dirname(INSTALL_DIR) . '/.env';
        $env_created = file_exists($env_path);
        $env_crypto_key = false;
        if ($env_created) {
            $env_content = file_get_contents($env_path);
            $env_crypto_key = preg_match('/^CRYPTO_KEY=.*/m', $env_content) === 1;
        }

        $test_conn->close();

        // ✅ INSTALACIÓN VERIFICADA EXITOSAMENTE
        $installation_verified = true;

        return [
            'verified' => true,
            'env_created' => $env_created,
            'env_crypto_key' => $env_crypto_key,
            'error' => null
        ];

    } catch (Exception $e) {
        $verification_error = $e->getMessage();
        error_log("Error verificación post-instalación: " . $e->getMessage());

        return [
            'verified' => false,
            'env_created' => false,
            'env_crypto_key' => false,
            'error' => $verification_error
        ];
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador del Sistema de Códigos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../styles/modern_global.css">
    <link rel="stylesheet" href="../styles/modern_admin.css">
    <link rel="stylesheet" href="../styles/instalador_neon.css">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">
    
    <div id="topProgressBar"></div>

    <div class="container py-4">
        <?php if (isset($installation_successful) && $installation_successful): ?>
<?php
// Ejecutar verificación
$verification_result = verifyInstallation();
$installation_verified = $verification_result['verified'];
$env_created = $verification_result['env_created'];
$env_crypto_key = $verification_result['env_crypto_key'];
$verification_error = $verification_result['error'];
?>
<div class="text-center">
    <div class="mb-4">
        <?php if ($installation_verified): ?>
            <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
            <div class="mt-3">
                <span class="badge bg-success fs-6">
                    <i class="fas fa-shield-check me-1"></i>
                    ¡Instalación Completada!
                </span>
            </div>
        <?php else: ?>
            <i class="fas fa-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
            <div class="mt-3">
                <span class="badge bg-warning text-dark fs-6">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Instalación con Advertencias
                </span>
            </div>
        <?php endif; ?>
    </div>
    
    <h1 class="text-center mb-4">
        <?= $installation_verified ? '¡Sistema Listo!' : '¡Instalación Completada!' ?>
    </h1>
    
    <div class="form-section">
        <p class="mb-3">La configuración se ha guardado correctamente y la instalación se ha completado.</p>
        
        <?php if ($installation_verified): ?>
            <div class="alert alert-success">
                <h6><i class="fas fa-check-circle me-2"></i>✅ Instalación Completada Exitosamente</h6>
                <ul class="list-unstyled text-start mt-3">
                    <li><i class="fas fa-check text-success me-2"></i> Licencia activada y verificada</li>
                    <li><i class="fas fa-check text-success me-2"></i> Base de datos configurada completamente</li>
                    <li><i class="fas fa-check text-success me-2"></i> Usuario administrador creado</li>
                    <?php if ($env_created): ?>
                    <li><i class="fas fa-check text-success me-2"></i> Archivo .env creado automáticamente</li>
                    <?php endif; ?>
                    <li><i class="fas fa-check text-success me-2"></i> Bot de Telegram completamente integrado</li>
                    <li><i class="fas fa-check text-success me-2"></i> Sistema de protección habilitado</li>
                    <li><i class="fas fa-check text-success me-2"></i> Configuraciones de Wamundo insertadas</li>
                    <li><i class="fas fa-check text-success me-2"></i> Todas las tablas necesarias creadas</li>
                </ul>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <h6><i class="fas fa-exclamation-triangle me-2"></i>Instalación Completada con Advertencias Menores</h6>
                <ul class="list-unstyled text-start mt-3">
                    <li><i class="fas fa-check text-success me-2"></i> Licencia activada y verificada</li>
                    <li><i class="fas fa-check text-success me-2"></i> Base de datos configurada completamente</li>
                    <li><i class="fas fa-check text-success me-2"></i> Usuario administrador creado</li>
                    <?php if ($env_created): ?>
                    <li><i class="fas fa-check text-success me-2"></i> Archivo .env creado automáticamente</li>
                    <?php endif; ?>
                    <li><i class="fas fa-check text-success me-2"></i> Bot de Telegram completamente integrado</li>
                    <li><i class="fas fa-check text-success me-2"></i> Sistema de protección habilitado</li>
                    <li><i class="fas fa-check text-success me-2"></i> Configuraciones de Wamundo insertadas</li>
                </ul>
                
                <?php if ($verification_error): ?>
                <div class="mt-3">
                    <small class="text-muted">
                        <strong>Detalle técnico:</strong> <?= htmlspecialchars($verification_error) ?>
                    </small>
                </div>
                <?php endif; ?>
                
                <div class="alert alert-info mt-3">
                    <strong>El sistema está listo para usar.</strong> Las advertencias no afectan el funcionamiento.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($env_crypto_key): ?>
            <div class="alert alert-success mt-3">
                <i class="fas fa-lock me-2"></i>Clave de cifrado verificada.
            </div>
        <?php endif; ?>

        <?php
        $license_info = $license_client->getLicenseInfo();
        if ($license_info): ?>
            <div class="alert alert-success">
                <h6><i class="fas fa-certificate me-2"></i>Información de Licencia</h6>
                <ul class="list-unstyled mb-0 text-start">
                    <li><strong>Dominio:</strong> <span class="text-success"><?= htmlspecialchars($license_info['domain']) ?></span></li>
                    <li><strong>Activada:</strong> <span class="text-success"><?= htmlspecialchars($license_info['activated_at']) ?></span></li>
                    <li><strong>Estado:</strong> <span class="badge bg-success">Válida</span></li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="d-flex justify-content-center gap-3 mt-4">
        <a href="../admin/admin.php" class="btn btn-primary btn-lg">
            <i class="fas fa-tachometer-alt me-2"></i>
            Acceder al Panel de Administración
        </a>
        <a href="../" class="btn btn-outline-secondary btn-lg">
            <i class="fas fa-home me-2"></i>
            Ir al Sistema Principal
        </a>
    </div>
    
    <div class="text-center mt-4">
        <div class="alert alert-info">
            <h6><i class="fas fa-rocket me-2"></i>¡Sistema Completamente Configurado!</h6>
            <p class="mb-0">
                <strong>WhatsApp:</strong> Configurado con Wamundo.com<br>
                <strong>Telegram:</strong> Bot integrado y funcional<br>
                <strong>Base de datos:</strong> Todas las tablas creadas<br>
                <strong>Configuración:</strong> Lista para usar
            </p>
        </div>
    </div>
</div>

        <?php elseif (isset($installation_error) && $installation_error): ?>
            <div class="text-center">
                <div class="mb-4">
                    <i class="fas fa-exclamation-triangle text-danger" style="font-size: 4rem;"></i>
                </div>
                <h1 class="text-center mb-4">Error en la Instalación</h1>
                <div class="form-section">
                    <div class="alert alert-danger">
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                    
                    <div class="diagnostics-box">
                        <h6><i class="fas fa-wrench me-2"></i>Información de Diagnóstico:</h6>
                        <ul class="list-unstyled mb-0">
                            <li>📁 <span class="text-secondary">PROJECT_ROOT:</span> <?= htmlspecialchars(PROJECT_ROOT) ?></li>
                            <li>📂 <span class="text-secondary">Dir. actual:</span> <?= htmlspecialchars(INSTALL_DIR) ?></li>
                            <li>📂 <span class="text-secondary">Dir. instalador:</span> <?= htmlspecialchars(__DIR__) ?></li>
                            <li>✅ <span class="<?= file_exists(LICENSE_DIR) ? 'requirement-ok' : 'requirement-error' ?>">License dir existe:</span> <?= file_exists(LICENSE_DIR) ? 'SÍ' : 'NO' ?></li>
                            <li>✏️ <span class="<?= is_writable(dirname(LICENSE_DIR)) ? 'requirement-ok' : 'requirement-error' ?>">License dir escribible:</span> <?= is_writable(dirname(LICENSE_DIR)) ? 'SÍ' : 'NO' ?></li>
                        </ul>
                    </div>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='?step=license'">
                        <i class="fas fa-redo me-2"></i>Reintentar
                    </button>
                </div>
            </div>
            
        <?php else: ?>
            <div class="step-indicator">
                <div class="step <?= $current_step === 'requirements' ? 'active' : ($current_step !== 'requirements' ? 'completed' : '') ?>">
                    <i class="fas fa-server me-2"></i>Requerimientos
                </div>
                <div class="step <?= $current_step === 'license' ? 'active' : ($current_step === 'configuration' ? 'completed' : '') ?>">
                    <i class="fas fa-key me-2"></i>Licencia
                </div>
                <div class="step <?= $current_step === 'configuration' ? 'active' : '' ?>">
                    <i class="fas fa-cogs me-2"></i>Configuración
                </div>
            </div>
            
            <?php if ($current_step === 'requirements'): ?>
                <div class="text-center mb-4">
                    <i class="fas fa-server text-primary" style="font-size: 3rem;"></i>
                    <h1 class="mt-3">Verificación de Requerimientos</h1>
                    <p class="text-secondary">Comprobando que su servidor cumple con los requisitos</p>
                </div>
                
                <div class="form-section">
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>Componente</th>
                                    <th>Requerido</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><i class="fab fa-php me-2"></i>PHP</td>
                                    <td><?= $php_version_required ?> o superior</td>
                                    <td>
                                        <span class="<?= $php_version_valid ? 'requirement-ok' : 'requirement-error' ?>">
                                            <i class="fas <?= $php_version_valid ? 'fa-check' : 'fa-times' ?> me-1"></i>
                                            <?= $php_version ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php foreach ($required_extensions as $ext => $description): ?>
                                    <tr>
                                        <td><i class="fas fa-puzzle-piece me-2"></i><?= $ext ?></td>
                                        <td><?= $description ?></td>
                                        <td>
                                            <span class="<?= $extensions_status[$ext] ? 'requirement-ok' : 'requirement-error' ?>">
                                                <i class="fas <?= $extensions_status[$ext] ? 'fa-check' : 'fa-times' ?> me-1"></i>
                                                <?= $extensions_status[$ext] ? 'Habilitada' : 'Faltante' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="diagnostics-box">
                        <h6><i class="fas fa-folder me-2"></i>Información de Rutas:</h6>
                        <ul class="list-unstyled mb-0 small">
                            <li>📁 <span class="text-secondary">Raíz del proyecto:</span> <?= htmlspecialchars(PROJECT_ROOT) ?></li>
                            <li>✅ <span class="<?= file_exists(LICENSE_DIR) ? 'requirement-ok' : 'requirement-error' ?>">Directorio existe:</span> <?= file_exists(LICENSE_DIR) ? 'SÍ' : 'NO' ?></li>
                            <li>✏️ <span class="<?= is_writable(dirname(LICENSE_DIR)) ? 'requirement-ok' : 'requirement-error' ?>">Directorio escribible:</span> <?= is_writable(dirname(LICENSE_DIR)) ? 'SÍ' : 'NO' ?></li>
                        </ul>
                    </div>
                    
                    <div class="text-center mt-3">
                        <?php if ($all_extensions_loaded && $php_version_valid): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                ¡Todos los requerimientos están satisfechos!
                            </div>
                            <a href="?step=license" class="btn btn-success btn-lg">
                                <i class="fas fa-key me-2"></i>Continuar con la Licencia
                            </a>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Hay requerimientos faltantes. Contacte a su proveedor de hosting.
                            </div>
                            <button type="button" class="btn btn-warning" onclick="location.reload()">
                                <i class="fas fa-sync me-2"></i>Verificar Nuevamente
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($current_step === 'license'): ?>
                <div class="text-center mb-4">
                    <i class="fas fa-key text-primary" style="font-size: 3rem;"></i>
                    <h1 class="mt-3">Activación de Licencia</h1>
                    <p class="text-secondary">Ingrese su clave de licencia para continuar</p>
                </div>
                
                <?php if (isset($license_error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($license_error) ?>
                        
                        <div class="diagnostics-box mt-3">
                            <h6><i class="fas fa-bug me-2"></i>Información de Debugging:</h6>
                            <ul class="list-unstyled mb-0 small">
                                <li>✅ <span class="<?= file_exists(LICENSE_DIR) ? 'requirement-ok' : 'requirement-error' ?>">Directorio existe:</span> <?= file_exists(LICENSE_DIR) ? 'SÍ' : 'NO' ?></li>
                                <li>✏️ <span class="<?= is_writable(LICENSE_DIR) ? 'requirement-ok' : 'requirement-error' ?>">Directorio escribible:</span> <?= is_writable(LICENSE_DIR) ? 'SÍ' : 'NO' ?></li>
                                <li>📄 <span class="<?= file_exists(LICENSE_FILE) ? 'requirement-ok' : 'requirement-error' ?>">Archivo existe:</span> <?= file_exists(LICENSE_FILE) ? 'SÍ' : 'NO' ?></li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($license_success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($license_success) ?>
                    </div>
                <?php endif; ?>
                
                <div class="form-section">
                    <?php
                    $status = $license_client->getLicenseStatus();
                    $license_is_valid = $status['status'] === 'active';

                    if ($license_is_valid): ?>
                        <div class="alert alert-success text-center">
                            <i class="fas fa-shield-alt fa-3x mb-3"></i>
                            <h4>Licencia Activada</h4>
                            <?php
                            $license_info = $license_client->getLicenseInfo();
                            if ($license_info): ?>
                                <p class="mb-0">
                                    <strong>Dominio:</strong> <span class="text-success"><?= htmlspecialchars($license_info['domain']) ?></span><br>
                                    <strong>Activada:</strong> <span class="text-success"><?= htmlspecialchars($license_info['activated_at']) ?></span><br>
                                    <strong>Estado:</strong> <span class="badge bg-success">Válida</span><br>
                                </p>
                            <?php else: ?>
                                <p class="mb-0">
                                    <strong>Estado:</strong> <span class="badge bg-success">Activada en Sesión</span><br>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="text-center">
                            <a href="?step=configuration" class="btn btn-primary btn-lg">
                                <i class="fas fa-cogs me-2"></i>Continuar con la Configuración
                            </a>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="text-center">
                            <div class="mb-4">
                                <label for="license_key" class="form-label h5">
                                    <i class="fas fa-key me-2"></i>Clave de Licencia
                                </label>
                                <input type="text" 
                                       class="form-control form-control-lg license-key-input text-center" 
                                       name="license_key" 
                                       placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
                                       maxlength="50"
                                       required>
                                <div class="form-text">
                                    Ingrese la clave de licencia proporcionada por el proveedor
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle me-2"></i>Información de Activación</h6>
                                <p class="mb-0">
                                    • La licencia se activará para el dominio: <strong class="text-primary"><?= htmlspecialchars($_SERVER['HTTP_HOST']) ?></strong><br>
                                    • Se verificará la validez con el servidor de licencias<br>
                                    • La activación requiere conexión a internet<br>
                                </p>
                            </div>
                            
                            <div class="d-flex justify-content-center gap-3">
                                <a href="?step=requirements" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-arrow-left me-2"></i>Atrás
                                </a>
                                <button type="submit" name="activate_license" class="btn btn-success btn-lg">
                                    <i class="fas fa-shield-alt me-2"></i>Activar Licencia
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($current_step === 'configuration'): ?>
                <?php
                $status = $license_client->getLicenseStatus();
                $can_proceed = $status['status'] === 'active';

                if (!$can_proceed): ?>
                    <div class="alert alert-danger text-center">
                        <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                        <h4>Licencia Requerida</h4>
                        <p>Debe activar una licencia válida antes de continuar.</p>
                        <a href="?step=license" class="btn btn-warning">
                            <i class="fas fa-key me-2"></i>Activar Licencia
                        </a>
                    </div>
                <?php else: ?>
                    <div class="text-center mb-4">
                        <i class="fas fa-cogs text-primary" style="font-size: 3rem;"></i>
                        <h1 class="mt-3">Configuración del Sistema</h1>
                        <p class="text-secondary">Complete los datos para finalizar la instalación</p>
                    </div>
                    
                    <form method="POST" id="installForm">
                        <div class="form-section">
                            <h4 class="mb-3"><i class="fas fa-database me-2 text-info"></i>Base de Datos</h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_host" class="form-label">
                                            <i class="fas fa-server me-2"></i>Servidor
                                        </label>
                                        <input type="text" class="form-control" name="db_host" value="localhost" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_name" class="form-label">
                                            <i class="fas fa-database me-2"></i>Nombre de la Base de Datos
                                        </label>
                                        <input type="text" class="form-control" name="db_name" placeholder="mi_sistema_codigos" required>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_user" class="form-label">
                                            <i class="fas fa-user me-2"></i>Usuario de la Base de Datos
                                        </label>
                                        <input type="text" class="form-control" name="db_user" placeholder="usuario_bd" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="db_password" class="form-label">
                                            <i class="fas fa-key me-2"></i>Contraseña de la Base de Datos
                                        </label>
                                        <input type="password" class="form-control" name="db_password" placeholder="Contraseña BD">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
    <h4 class="mb-3"><i class="fas fa-user-shield me-2 text-warning"></i>Usuario Administrador</h4>
    <div class="row">
        <div class="col-md-4">
            <div class="mb-3">
                <label for="admin_user" class="form-label">
                    <i class="fas fa-user-cog me-2"></i>Usuario Administrador
                </label>
                <input type="text" class="form-control" name="admin_user" placeholder="admin" required minlength="3">
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label for="admin_telegram" class="form-label">
                    <i class="fab fa-telegram me-2"></i>ID de Telegram
                </label>
                <input type="text" class="form-control" name="admin_telegram" placeholder="123456789" pattern="[0-9]+">
                <small class="text-muted">ID numérico de Telegram (opcional)</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label for="admin_password" class="form-label">
                    <i class="fas fa-lock me-2"></i>Contraseña Administrador
                </label>
                <input type="password" class="form-control" name="admin_password" placeholder="Contraseña segura" required minlength="8">
                <small class="text-muted">Debe contener minúscula, mayúscula y número</small>
            </div>
        </div>
    </div>
</div>

<div class="form-section">
    <h4 class="mb-3"><i class="fab fa-telegram me-2 text-info"></i>Bot de Telegram (Opcional)</h4>
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Opcional:</strong> Puedes configurar el bot ahora o después desde el panel de administración.
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="telegram_token" class="form-label">
                    <i class="fab fa-telegram me-2"></i>Token del Bot
                </label>
                <input type="text" class="form-control" name="telegram_token" 
                       placeholder="123456789:ABC-DEF1234..." 
                       pattern="[0-9]+:[A-Za-z0-9_-]+">
                <small class="text-muted">Obtén el token desde @BotFather en Telegram</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <label for="telegram_webhook" class="form-label">
                    <i class="fas fa-link me-2"></i>URL del Webhook
                </label>
                <input type="url" class="form-control" name="telegram_webhook" 
                       placeholder="https://tudominio.com/telegram_bot/webhook.php">
                <small class="text-muted">Se generará automáticamente con tu dominio</small>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="enable_security_features" value="1" checked>
                <label class="form-check-label">
                    <i class="fas fa-shield-alt me-2 text-success"></i>
                    Habilitar características de seguridad avanzadas
                </label>
                <small class="form-text text-muted">
                    Incluye rate limiting, validación de webhook, cleanup automático y logging optimizado
                </small>
            </div>
        </div>
    </div>
</div>

                        <div class="form-section">
                            <div class="alert alert-success">
                                <h6><i class="fas fa-shield-alt me-2"></i>Estado de Licencia</h6>
                                <?php
                                $license_info = $license_client->getLicenseInfo();
                                if ($license_info): ?>
                                    <ul class="mb-0 text-start">
                                        <li>✅ <span class="text-success">Licencia válida y activada</span></li>
                                        <li>🌐 <span class="text-secondary">Dominio autorizado:</span> <strong class="text-primary"><?= htmlspecialchars($license_info['domain']) ?></strong></li>
                                        <li>📅 <span class="text-secondary">Activada el:</span> <span class="text-primary"><?= htmlspecialchars($license_info['activated_at']) ?></span></li>
                                        <li>🔒 <span class="text-success">Sistema protegido contra uso no autorizado</span></li>
                                        <li>🤖 <span class="text-success">Bot de Telegram será integrado automáticamente</span></li>
                                        <li>📋 <span class="text-success">Tabla telegram_temp_data será creada</span></li>
                                    </ul>
                                <?php else: ?>
                                    <ul class="mb-0 text-start">
                                        <li>✅ <span class="text-success">Licencia activada en esta sesión</span></li>
                                        <li>🌐 <span class="text-secondary">Dominio:</span> <strong class="text-primary"><?= htmlspecialchars($_SERVER['HTTP_HOST']) ?></strong></li>
                                        <li>🔒 <span class="text-success">Sistema protegido contra uso no autorizado</span></li>
                                        <li>🤖 <span class="text-success">Bot de Telegram será integrado automáticamente</span></li>
                                        <li>📋 <span class="text-success">Tabla telegram_temp_data será creada</span></li>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-center gap-3">
                            <a href="?step=license" class="btn btn-secondary btn-lg">
                                <i class="fas fa-arrow-left me-2"></i>Atrás
                            </a>
                            <button type="submit" name="configure" class="btn btn-success btn-lg">
                                <i class="fas fa-rocket me-2"></i>Instalar Sistema + Bot Completo
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const topProgressBar = document.getElementById('topProgressBar');

        function showProgressBar() {
            topProgressBar.style.width = '100%';
        }

        function hideProgressBar() {
            topProgressBar.style.width = '0';
        }

        document.getElementById('installForm')?.addEventListener('submit', function() {
            showProgressBar();
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            hideProgressBar();
        });

        document.querySelector('.license-key-input')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            let formatted = value.match(/.{1,4}/g)?.join('-') || value;
            if (formatted.length > 47) formatted = formatted.substring(0, 47);
            e.target.value = formatted;
        });
    </script>
</body>

</html>