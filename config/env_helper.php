<?php
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
?>