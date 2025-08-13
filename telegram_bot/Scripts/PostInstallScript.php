<?php
namespace TelegramBot\Scripts;

use Composer\Script\Event;

class PostInstallScript
{
    public static function execute(Event $event = null)
    {
        echo "\n🚀 Ejecutando post-instalación del Bot de Telegram...\n";
        
        $rootDir = self::getRootDirectory();
        
        self::ensureDirectoryStructure($rootDir);
        self::verifyAutoloader($rootDir);
        self::setCorrectPermissions($rootDir);
        
        echo "✅ Post-instalación completada exitosamente.\n\n";
    }
    
    private static function getRootDirectory()
    {
        $current = __DIR__;
        while ($current !== "/" && !file_exists($current . "/composer.json")) {
            $current = dirname($current);
        }
        
        if (!file_exists($current . "/composer.json")) {
            $current = getcwd();
        }
        
        return $current;
    }
    
    private static function ensureDirectoryStructure($rootDir)
    {
        $directories = [
            "telegram_bot/logs",
            "telegram_bot/cache", 
            "cache"
        ];
        
        foreach ($directories as $dir) {
            $fullPath = $rootDir . "/" . $dir;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
        }
    }
    
    private static function verifyAutoloader($rootDir)
    {
        $autoloaderPath = $rootDir . "/vendor/autoload.php";
        
        if (file_exists($autoloaderPath)) {
            require_once $autoloaderPath;
        }
        
        $testClasses = [
            "TelegramBot\\Services\\TelegramAuth",
            "TelegramBot\\Services\\TelegramQuery"
        ];
        
        $allWorking = true;
        foreach ($testClasses as $class) {
            if (!class_exists($class)) {
                $allWorking = false;
                break;
            }
        }
        
        if (!$allWorking) {
            self::applyAutoloaderFix($rootDir);
        }
    }
    
    private static function applyAutoloaderFix($rootDir)
    {
        $fixContent = '<?php
if (!defined("TELEGRAM_BOT_AUTOLOADER_FIX")) {
    define("TELEGRAM_BOT_AUTOLOADER_FIX", true);
    
    spl_autoload_register(function ($className) {
        $projectRoot = __DIR__ . "/..";
        
        if (strpos($className, "TelegramBot\\\\") === 0) {
            $relativePath = substr($className, strlen("TelegramBot\\\\"));
            $filePath = $projectRoot . "/telegram_bot/" . str_replace("\\\\", "/", $relativePath) . ".php";
            
            if (file_exists($filePath)) {
                require_once $filePath;
                return true;
            }
        }
        
        if (strpos($className, "Shared\\\\") === 0) {
            $relativePath = substr($className, strlen("Shared\\\\"));
            $filePath = $projectRoot . "/shared/" . str_replace("\\\\", "/", $relativePath) . ".php";
            
            if (file_exists($filePath)) {
                require_once $filePath;
                return true;
            }
        }
        
        return false;
    });
}
';
        
        $fixPath = $rootDir . "/vendor/autoload_fix.php";
        file_put_contents($fixPath, $fixContent);
        
        $mainAutoloader = $rootDir . "/vendor/autoload.php";
        if (file_exists($mainAutoloader)) {
            $content = file_get_contents($mainAutoloader);
            
            if (strpos($content, "autoload_fix.php") === false) {
                $content .= "\n// TelegramBot autoloader fix\nif (file_exists(__DIR__ . '/autoload_fix.php')) { require_once __DIR__ . '/autoload_fix.php'; }\n";
                file_put_contents($mainAutoloader, $content);
            }
        }
    }
    
    private static function setCorrectPermissions($rootDir)
    {
        $permissions = [
            "telegram_bot/logs" => 0755,
            "telegram_bot/cache" => 0755,
            "cache" => 0755
        ];
        
        foreach ($permissions as $path => $perm) {
            $fullPath = $rootDir . "/" . $path;
            if (file_exists($fullPath)) {
                chmod($fullPath, $perm);
            }
        }
    }
}
