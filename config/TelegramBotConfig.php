<?php
require_once __DIR__ . '/path_constants.php';
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';
// config/TelegramBotConfig.php - Configuración del Bot de Telegram
// Integrado con la base de datos del panel de administración

class TelegramBotConfig {
    private static $db = null;
    
    // Configuración por defecto
    public static $BOT_TOKEN = '';
    public static $WEBHOOK_URL = '';
    public static $WEBHOOK_SECRET = '';
    
    // Constantes del bot
    public const MAX_MESSAGE_LENGTH = 4096;
    public const RATE_LIMIT_WINDOW = 60;
    public const MAX_REQUESTS_PER_MINUTE = 30;
    
    public const COMMANDS = [
        'start' => 'Iniciar bot y vincular cuenta',
        'buscar' => 'Buscar códigos por email y plataforma',
        'codigo' => 'Obtener código por ID',
        'ayuda' => 'Mostrar esta ayuda',
        'config' => 'Ver tu configuración personal'
    ];
    
    /**
     * Carga la configuración usando ConfigService
     */
    public static function load() {
        if (!empty(self::$BOT_TOKEN)) {
            return;
        }

        try {
            $config = Shared\ConfigService::getInstance();
            self::$BOT_TOKEN = $config->get('TELEGRAM_BOT_TOKEN', '');
            self::$WEBHOOK_URL = $config->get('TELEGRAM_WEBHOOK_URL', '');
            self::$WEBHOOK_SECRET = $config->get('TELEGRAM_WEBHOOK_SECRET', '');
        } catch (\Throwable $e) {
            error_log("Error cargando configuración del bot: " . $e->getMessage());
        }
    }

    /**
     * Carga conexión a la base de datos
     */
    private static function loadDatabaseConnection() {
        self::$db = Shared\DatabaseManager::getInstance()->getConnection();
    }
    
    /**
     * Obtiene la conexión a la base de datos
     */
    public static function getDatabaseConnection() {
        if (!self::$db) {
            self::loadDatabaseConnection();
        }
        return self::$db;
    }
    
    /**
     * Verifica si la configuración está completa
     */
    public static function isConfigured() {
        self::load();
        return !empty(self::$BOT_TOKEN) && !empty(self::$WEBHOOK_URL);
    }
    
    /**
     * Registra actividad del bot en la base de datos
     */
    public static function logActivity($user_id, $telegram_id, $action, $details = '') {
        try {
            if (!self::$db) {
                self::loadDatabaseConnection();
            }
            
            $stmt = self::$db->prepare("INSERT INTO telegram_bot_logs (user_id, telegram_id, action, details) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $user_id, $telegram_id, $action, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error registrando actividad del bot: " . $e->getMessage());
        }
    }
    
    /**
     * Obtiene información de usuario por telegram_id
     */
    public static function getUserByTelegramId($telegram_id) {
        try {
            if (!self::$db) {
                self::loadDatabaseConnection();
            }
            
            $stmt = self::$db->prepare("SELECT id, username, telegram_id FROM users WHERE telegram_id = ?");
            $stmt->bind_param("s", $telegram_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return $row;
            }
            
            $stmt->close();
            return null;
        } catch (Exception $e) {
            error_log("Error obteniendo usuario: " . $e->getMessage());
            return null;
        }
    }
}

// Auto-cargar configuración al incluir el archivo
TelegramBotConfig::load();
?>