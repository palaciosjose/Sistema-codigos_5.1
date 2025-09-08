<?php
// whatsapp_bot/handlers/CommandHandler.php
namespace WhatsappBot\Handlers;

use WhatsappBot\Services\WhatsappAuth;
use WhatsappBot\Services\WhatsappQuery;
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

        $messages = include dirname(__DIR__) . '/templates/messages.php';

        if (preg_match('/^\/ayuda$/i', $text)) {
            self::sendMessage($chatId, $messages['help']);
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

        self::sendMessage($chatId, $messages['unknown_command']);
    }

    /**
     * Envía un mensaje utilizando la API de WhatsApp.
     */
    private static function sendMessage(string $chatId, string $text): void
    {
        WhatsappAPI::sendMessage($chatId, $text);
    }
}

