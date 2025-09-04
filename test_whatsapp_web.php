<?php
require_once __DIR__ . '/config/path_constants.php';
/**
 * Pruebas web del Bot de WhatsApp
 */

echo "<h1>🧪 Pruebas del Bot de WhatsApp</h1>";

$errors = [];

echo "<h2>1️⃣ Test de carga de clases</h2>";
if (file_exists(PROJECT_ROOT . "/vendor/autoload.php")) {
    require_once PROJECT_ROOT . "/vendor/autoload.php";
    echo "<p>✅ Autoloader cargado</p>";

    $testClasses = [
        "WhatsappBot\\Services\\WhatsappAuth",
        "WhatsappBot\\Services\\WhatsappQuery"
    ];

    echo "<ul>";
    foreach ($testClasses as $class) {
        if (class_exists($class)) {
            echo "<li>✅ $class</li>";
        } else {
            echo "<li>❌ $class</li>";
            $errors[] = "Clase $class no se puede cargar";
        }
    }
    echo "</ul>";
} else {
    echo "<p>❌ vendor/autoload.php no encontrado</p>";
    $errors[] = "Autoloader no encontrado";
}

echo "<h2>2️⃣ Test de webhook</h2>";
if (file_exists(PROJECT_ROOT . "/whatsapp_bot/webhook.php")) {
    echo "<p>✅ webhook.php encontrado</p>";
} else {
    echo "<p>❌ webhook.php no encontrado</p>";
    $errors[] = "webhook.php no encontrado";
}

echo "<h2>3️⃣ Test de base de datos</h2>";
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
use Shared\DatabaseManager;

if (extension_loaded('mysqli')) {
    try {
        $testDb = DatabaseManager::getInstance()->getConnection();
        echo "<p>✅ Conexión exitosa</p>";
    } catch (\Throwable $e) {
        echo "<p>⚠️ Error: " . $e->getMessage() . " (ignorado)</p>";
    }
} else {
    echo "<p>⚠️ Extensión mysqli no disponible, prueba omitida</p>";
}

echo "<h2>4️⃣ Test de variables de entorno</h2>";

$apiUrl   = getenv('WHATSAPP_API_URL');
$apiToken = getenv('WHATSAPP_API_TOKEN');

if ($apiUrl) {
    echo "<p>✅ WHATSAPP_API_URL: " . htmlspecialchars($apiUrl) . "</p>";
} else {
    echo "<p>❌ WHATSAPP_API_URL no configurada</p>";
    $errors[] = 'WHATSAPP_API_URL no configurada';
}

if ($apiToken) {
    echo "<p>✅ WHATSAPP_API_TOKEN establecido (" . strlen($apiToken) . " caracteres)</p>";
} else {
    echo "<p>❌ WHATSAPP_API_TOKEN no configurado</p>";
    $errors[] = 'WHATSAPP_API_TOKEN no configurado';
}

$runInstanceInfo = (PHP_SAPI === 'cli' && in_array('--instance-info', $argv ?? [])) || isset($_GET['instance_info']);
if ($runInstanceInfo) {
    echo "<h2>🧪 Información de la instancia</h2>";
    try {
        $info = \WhatsappBot\Utils\WhatsappAPI::getInstanceInfo();
        echo '<pre>' . htmlspecialchars(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
    } catch (\Throwable $e) {
        echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        $errors[] = 'Error getInstanceInfo: ' . $e->getMessage();
    }
}

echo "<h2>📊 RESUMEN</h2>";
if (empty($errors)) {
    echo "<div style=\"background: #d4edda; color: #155724; padding: 15px; border-radius: 5px;\">";
    echo "<h3>✅ Todas las pruebas pasaron</h3>";
    echo "<p>🎉 El bot está listo para usar</p>";
    echo "</div>";
} else {
    echo "<div style=\"background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px;\">";
    echo "<h3>❌ Se encontraron errores:</h3>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>$error</li>";
    }
    echo "</ul>";
    echo "</div>";
}

echo "<p><a href=\"setup_whatsapp_web.php\">🔙 Volver a Configuración</a></p>";
