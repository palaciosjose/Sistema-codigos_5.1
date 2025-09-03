<?php
require_once __DIR__ . '/../config/path_constants.php';
require_once PROJECT_ROOT . '/shared/DatabaseManager.php';
require_once PROJECT_ROOT . '/shared/Crypto.php';
require_once PROJECT_ROOT . '/shared/ConfigService.php';

use Shared\DatabaseManager;
use Shared\Crypto;
use Shared\ConfigService;

try {
    $db = DatabaseManager::getInstance()->getConnection();
    $result = $db->query("SELECT name, value FROM settings WHERE name IN ('TELEGRAM_BOT_TOKEN','TELEGRAM_WEBHOOK_SECRET')");
    $updated = 0;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $name = $row['name'];
            $value = $row['value'];
            if ($value !== '' && !Crypto::isEncrypted($value)) {
                $enc = Crypto::encrypt($value);
                $stmt = $db->prepare("UPDATE settings SET value=? WHERE name=?");
                $stmt->bind_param('ss', $enc, $name);
                $stmt->execute();
                $stmt->close();
                $updated++;
            }
        }
        $result->close();
    }
    ConfigService::getInstance()->reload();
    echo "Encrypted $updated setting(s).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
