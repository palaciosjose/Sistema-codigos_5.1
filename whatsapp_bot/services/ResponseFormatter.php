<?php
namespace TelegramBot\Services;

use TelegramBot\Config\TelegramBotConfig;

/**
 * Formateador de mensajes para respuestas del bot.
 */
class ResponseFormatter
{
    /**
     * Formatea los resultados de búsqueda y los divide en múltiples mensajes si es necesario.
     *
     * @param array $result Resultado entregado por el motor
     * @return array Lista de mensajes listos para enviar
     */
    public static function formatSearchResults(array $result): array
    {
        if (isset($result['error'])) {
            $text = $result['error'];
        } elseif (($result['found'] ?? false) === true) {
            if (!empty($result['emails']) && is_array($result['emails'])) {
                $emails = array_slice($result['emails'], 0, 3);
                $lines = [];
                foreach ($emails as $email) {
                    $from = $email['from'] ?? 'Desconocido';
                    $subject = $email['subject'] ?? 'Sin asunto';
                    $code = $email['verification_code'] ?? ($email['access_link'] ?? 'N/A');
                    $lines[] = "De: {$from}\nAsunto: {$subject}\nDato: {$code}";
                }
                $count = count($result['emails']);
                $text = "*Éxito:* {$count} emails encontrados.\n\n" . implode("\n\n", $lines);
                if ($count > count($emails)) {
                    $text .= "\n\n...y más.";
                }
            } else {
                $text = "*Éxito:* " . ($result['content'] ?? '');
            }
        } else {
            $text = $result['message'] ?? 'Sin resultados.';
        }

        $text = self::escapeMarkdown($text);
        $chunks = self::paginate($text, TelegramBotConfig::MAX_MESSAGE_LENGTH - 50);
        return $chunks;
    }

    public static function formatCodeResult(array $result): array
    {
        if (isset($result['error'])) {
            $text = $result['error'];
        } elseif (($result['found'] ?? false) === true) {
            $text = json_encode($result['content'], JSON_PRETTY_PRINT);
        } else {
            $text = 'Sin resultados.';
        }

        $text = self::escapeMarkdown($text);
        return self::paginate($text, TelegramBotConfig::MAX_MESSAGE_LENGTH - 50);
    }

    /**
     * Divide un texto largo en bloques del tamaño especificado.
     */
    public static function paginate(string $text, int $limit): array
    {
        $messages = [];
        while (strlen($text) > $limit) {
            $messages[] = substr($text, 0, $limit);
            $text = substr($text, $limit);
        }
        $messages[] = $text;
        return $messages;
    }

    /**
     * Escapa caracteres de Markdown para Telegram.
     */
    public static function escapeMarkdown(string $text): string
    {
        $escape = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($escape as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }
}
