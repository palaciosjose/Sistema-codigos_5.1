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

try {
    $testDb = DatabaseManager::getInstance()->getConnection();
    echo "<p>✅ Conexión exitosa</p>";
} catch (\Throwable $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
    $errors[] = "Error de BD: " . $e->getMessage();
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
