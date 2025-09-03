<?php
require_once __DIR__ . '/config/path_constants.php';
/**
 * Configuración web del Bot de Telegram
 * Ejecutar desde navegador después de subir archivos
 */

echo "<h1>🤖 Configuración del Bot de Telegram</h1>";

if (!file_exists(PROJECT_ROOT . "/composer.json")) {
    echo "<p style=\"color: red;\">❌ Error: composer.json no encontrado</p>";
    exit;
}

if (!file_exists(PROJECT_ROOT . "/vendor/autoload.php")) {
    echo "<p style=\"color: orange;\">⚠️ Advertencia: vendor/autoload.php no encontrado</p>";
    echo "<p>Ejecuta en terminal: <code>composer install</code></p>";
    echo "<p>O sube manualmente el directorio vendor/</p>";
} else {
    require_once PROJECT_ROOT . "/vendor/autoload.php";
    echo "<p style=\"color: green;\">✅ Autoloader cargado</p>";
}

echo "<h2>📋 Verificando sistema:</h2>";

$phpVersion = PHP_VERSION;
echo "<p>🔍 PHP Version: $phpVersion</p>";

$requiredExtensions = ["mysqli", "curl", "json", "mbstring", "imap"];
echo "<h3>Extensiones PHP:</h3><ul>";
foreach ($requiredExtensions as $ext) {
    $status = extension_loaded($ext) ? "✅" : "❌";
    echo "<li>$status $ext</li>";
}
echo "</ul>";

if (file_exists(PROJECT_ROOT . "/vendor/autoload.php")) {
    echo "<h3>Clases del Bot:</h3><ul>";
    $testClasses = [
        "TelegramBot\\Services\\TelegramAuth",
        "TelegramBot\\Services\\TelegramQuery"
    ];
    
    foreach ($testClasses as $class) {
        $status = class_exists($class) ? "✅" : "❌";
        echo "<li>$status $class</li>";
    }
    echo "</ul>";
}

echo "<h2>🚀 Próximos pasos:</h2>";
echo "<ol>";
echo "<li>Define las credenciales en variables de entorno o en <code>config/db_credentials.php</code></li>";
echo "<li>Ve al panel de administración: <a href=\"admin/telegram_management.php\">Panel Admin</a></li>";
echo "<li>Configura el token del bot y webhook</li>";
echo "<li>Prueba el bot enviando /start</li>";
echo "</ol>";

echo "<p><a href=\"test_web.php\">🧪 Ejecutar Pruebas</a></p>";
