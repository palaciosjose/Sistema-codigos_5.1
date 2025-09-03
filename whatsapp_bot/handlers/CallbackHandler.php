<?php
// whatsapp_bot/handlers/CallbackHandler.php
namespace WhatsappBot\Handlers;

use WhatsappBot\Services\WhatsappAuth;
use WhatsappBot\Services\WhatsappQuery;
use WhatsappBot\Utils\WhatsappAPI;

/**
 * Manejador de callbacks del bot de WhatsApp.
 *
 * Los callbacks provienen de interacciones de botones en Whaticket,
 * las cuales envían un payload con `chat_id`, `whatsapp_id` y `data`.
 */
class CallbackHandler
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
     * Procesa un callback adaptado desde Whaticket.
     *
     * @param array $payload Datos del callback: chat_id, whatsapp_id y data.
     */
    public static function handle(array $payload): void
    {
        self::init();

        $chatId     = $payload['chat_id'] ?? null;
        $data       = $payload['data'] ?? null;
        $whatsappId = $payload['whatsapp_id'] ?? null;

        if (!$chatId || !$whatsappId || !$data) {
            return;
        }

        $user = self::$auth->authenticateUser((int)$whatsappId);

        $messages = include dirname(__DIR__) . '/templates/messages.php';

        switch ($data) {
            case 'help':
                self::sendMessage($chatId, $messages['help']);
                break;

            case 'search_menu':
                if (!$user) {
                    self::sendMessage($chatId, $messages['unauthorized']);
                    break;
                }
                self::sendMessage($chatId, $messages['search_instructions']);
                break;

            case 'start_menu':
                if (!$user) {
                    self::sendMessage($chatId, $messages['unauthorized']);
                    break;
                }
                self::sendMessage($chatId, $messages['welcome']);
                break;

            case 'stats':
                if (!$user) {
                    self::sendMessage($chatId, $messages['unauthorized']);
                    break;
                }
                $stats = self::$query->getStats();
                $msg = "📊 *Estadísticas del Bot*\n\n" .
                       "👥 Usuarios activos: *{$stats['active_users']}*\n" .
                       "🔍 Búsquedas hoy: *{$stats['searches_today']}*\n" .
                       "📈 Total búsquedas: *{$stats['total_searches']}*";
                self::sendMessage($chatId, $msg);
                break;

            default:
                self::sendMessage($chatId, $messages['invalid_format']);
                break;
        }
    }

    /**
     * Envía un mensaje a través de la API de WhatsApp.
     */
    private static function sendMessage(string $chatId, string $text): void
    {
        WhatsappAPI::sendMessage($chatId, $text);
    }
}

