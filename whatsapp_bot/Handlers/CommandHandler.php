<?php
// whatsapp_bot/handlers/CommandHandler.php
namespace WhatsappBot\Handlers;

use WhatsappBot\Services\WhatsappAuth;
use WhatsappBot\Services\WhatsappQuery;
use WhatsappBot\Services\ResponseFormatter;
use WhatsappBot\Utils\WhatsappAPI;

/**
 * Gestiona comandos básicos recibidos mediante Wamundo.
 */
class CommandHandler
{
    private static ?WhatsappAuth $auth = null;
    private static ?WhatsappQuery $query = null;

    private static function init(): void
    {
        if (!self::$auth) {
            self::$auth = new WhatsappAuth();
            self::$query = new WhatsappQuery(self::$auth);
        }
    }

    /**
     * Maneja un mensaje tipo comando desde Wamundo.
     *
     * @param array $payload Debe contener `chat_id`, `whatsapp_id` y `text`.
     */
    public static function handle(array $payload): void
    {
        self::init();

        $chatId     = $payload['chat_id'] ?? null;
        $text       = trim($payload['text'] ?? '');
        $whatsappId = $payload['whatsapp_id'] ?? null;

        if (!$chatId || !$whatsappId || $text === '') {
            return;
        }

        if (preg_match('/^\/start$/i', $text)) {
            self::sendMessage($chatId, self::getMessage('welcome'));
            return;
        }

        if (preg_match('/^\/ayuda$/i', $text)) {
            self::sendMessage($chatId, self::getMessage('help'));
            return;
        }

        if (preg_match('/^\/buscar\b/i', $text)) {
            self::handleSearchCommand($chatId, (int)$whatsappId, $text);
            return;
        }

        if (preg_match('/^\/codigo\b/i', $text)) {
            self::handleCodeCommand($chatId, (int)$whatsappId, $text);
            return;
        }

        if (preg_match('/^\/stats$/i', $text)) {
            $stats = self::$query->getStats();
            $msg = "📊 *Estadísticas del Bot*\n\n" .
                   "👥 Usuarios activos: *{$stats['active_users']}*\n" .
                   "🔍 Búsquedas hoy: *{$stats['searches_today']}*\n" .
                   "📈 Total búsquedas: *{$stats['total_searches']}*";
            self::sendMessage($chatId, $msg);
            return;
        }

        self::sendMessage($chatId, self::getMessage('unknown_command'));
    }

    /**
     * Envía un mensaje utilizando la API de WhatsApp.
     */
    private static function sendMessage(string $chatId, string $text): void
    {
        WhatsappAPI::sendMessage($chatId, $text);
    }

    private static function getMessage(string $key): string
    {
        static $messages = null;

        if ($messages === null) {
            $messages = include dirname(__DIR__) . '/templates/messages.php';
        }

        return $messages[$key] ?? "Mensaje no encontrado: $key";
    }

    private static function handleSearchCommand(string $chatId, int $whatsappId, string $text): void
    {
        $parts = explode(' ', $text);
        $email = $parts[1] ?? '';
        $platform = $parts[2] ?? '';

        if ($email === '' || $platform === '') {
            self::sendMessage($chatId, self::getMessage('usage_search'));
            return;
        }

        self::sendMessage($chatId, self::getMessage('searching'));

        try {
            $result = self::$query->processSearchRequest($whatsappId, (int)$chatId, $email, $platform);
            $messages = ResponseFormatter::formatSearchResults($result);
            foreach ($messages as $msg) {
                self::sendMessage($chatId, $msg);
                usleep(100000);
            }
        } catch (\Exception $e) {
            self::sendMessage($chatId, self::getMessage('server_error'));
        }
    }

    private static function handleCodeCommand(string $chatId, int $whatsappId, string $text): void
    {
        $parts = explode(' ', $text);
        $codeId = $parts[1] ?? '';

        if ($codeId === '' || !is_numeric($codeId)) {
            self::sendMessage($chatId, self::getMessage('usage_code'));
            return;
        }

        try {
            $result = self::$query->getCodeById($whatsappId, (int)$codeId);
            $messages = ResponseFormatter::formatCodeResult($result);
            foreach ($messages as $msg) {
                self::sendMessage($chatId, $msg);
                usleep(100000);
            }
        } catch (\Exception $e) {
            self::sendMessage($chatId, self::getMessage('error_code'));
        }
    }
}

