<?php
/**
 * Pruebas web del Bot de Telegram
 */

echo "<h1>🧪 Pruebas del Bot de Telegram</h1>";

$errors = [];

echo "<h2>1️⃣ Test de carga de clases</h2>";
if (file_exists("vendor/autoload.php")) {
    require_once "vendor/autoload.php";
    echo "<p>✅ Autoloader cargado</p>";
    
    $testClasses = [
        "TelegramBot\\Services\\TelegramAuth",
        "TelegramBot\\Services\\TelegramQuery"
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
if (file_exists("telegram_bot/webhook.php")) {
    echo "<p>✅ webhook.php encontrado</p>";
} else {
    echo "<p>❌ webhook.php no encontrado</p>";
    $errors[] = "webhook.php no encontrado";
}

echo "<h2>3️⃣ Test de base de datos</h2>";
if (file_exists("instalacion/basededatos.php")) {
    include "instalacion/basededatos.php";
    
    if (isset($db_host, $db_user, $db_password, $db_name)) {
        echo "<p>✅ Variables de configuración encontradas</p>";
        
        try {
            $testDb = new mysqli($db_host, $db_user, $db_password, $db_name);
            if ($testDb->connect_error) {
                echo "<p>❌ Error de conexión: " . $testDb->connect_error . "</p>";
                $errors[] = "Error de conexión a BD";
            } else {
                echo "<p>✅ Conexión exitosa</p>";
                $testDb->close();
            }
        } catch (Exception $e) {
            echo "<p>❌ Error: " . $e->getMessage() . "</p>";
            $errors[] = "Error de BD: " . $e->getMessage();
        }
    } else {
        echo "<p>❌ Variables de configuración no encontradas</p>";
        $errors[] = "Variables de BD no definidas";
    }
} else {
    echo "<p>❌ Archivo de configuración de BD no encontrado</p>";
    $errors[] = "basededatos.php no encontrado";
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

echo "<p><a href=\"setup_web.php\">🔙 Volver a Configuración</a></p>";
