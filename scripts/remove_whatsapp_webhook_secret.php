<?php
require_once __DIR__ . '/../config/path_constants.php';
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';

use Shared\DatabaseManager;
use Shared\ConfigService;

try {
    $db = DatabaseManager::getInstance()->getConnection();
    $stmt = $db->prepare("DELETE FROM settings WHERE name = 'WHATSAPP_NEW_WEBHOOK_SECRET'");
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    ConfigService::getInstance()->reload();
    echo "Removed $deleted obsolete setting(s).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
