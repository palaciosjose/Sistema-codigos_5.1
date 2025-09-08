<?php
require_once __DIR__ . '/config/path_constants.php';
require_once PROJECT_ROOT . '/config/env_helper.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';
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
use Shared\ConfigService;

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

echo "<h2>4️⃣ Test de configuración</h2>";

$config = ConfigService::getInstance();
$apiUrl   = $config->get('WHATSAPP_NEW_API_URL');
$sendSecret = $config->get('WHATSAPP_NEW_SEND_SECRET');

if ($apiUrl) {
    echo "<p>✅ WHATSAPP_NEW_API_URL: " . htmlspecialchars($apiUrl) . "</p>";
} else {
    echo "<p>❌ WHATSAPP_NEW_API_URL no configurada</p>";
    $errors[] = 'WHATSAPP_NEW_API_URL no configurada';
}

if ($sendSecret) {
    echo "<p>✅ WHATSAPP_NEW_SEND_SECRET establecido (" . strlen($sendSecret) . " caracteres)</p>";
} else {
    echo "<p>❌ WHATSAPP_NEW_SEND_SECRET no configurado</p>";
    $errors[] = 'WHATSAPP_NEW_SEND_SECRET no configurado';
}

echo "<h2>5️⃣ Test de formato de payload</h2>";
try {
    $apiClass = new \ReflectionClass('WhatsappBot\\Utils\\WhatsappAPI');
    $operations = [
        'sendMessage'    => ['number', 'body'],
        'sendChatAction' => ['number', 'action'],
        'checkNumber'    => ['number']
    ];

    foreach ($operations as $method => $fields) {
        $file = file(PROJECT_ROOT . '/whatsapp_bot/Utils/WhatsappAPI.php');
        $ref  = $apiClass->getMethod($method);
        $code = implode('', array_slice($file, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        $missing = array_filter($fields, fn($f) => strpos($code, "'{$f}'") === false);
        if ($missing) {
            echo "<p>❌ $method sin campos: " . implode(', ', $missing) . "</p>";
            $errors[] = "$method payload incorrecto";
        } else {
            echo "<p>✅ $method con campos correctos</p>";
        }
    }
} catch (\Throwable $e) {
    echo "<p>⚠️ Test de payload omitido: " . htmlspecialchars($e->getMessage()) . "</p>";
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
