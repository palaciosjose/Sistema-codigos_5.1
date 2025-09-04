<?php
namespace WhatsappBot\Utils;

require_once __DIR__ . '/../config/whatsapp_config.php';

use RuntimeException;

/**
 * Cliente estático para interactuar con la API de WhatsApp.
 */
class WhatsappAPI
{
    /**
     * Envía un mensaje de texto a un número de WhatsApp.
     */
    public static function sendMessage(string $number, string $text): array
    {
        $payload = [
            'number' => $number,
            'body'  => $text,
        ];

        if (\WhatsappBot\Config\WHATSAPP_INSTANCE_ID !== '') {
            $payload['whatsappId'] = \WhatsappBot\Config\WHATSAPP_INSTANCE_ID;
        }

        return self::makeRequest('/api/messages/send', $payload);
    }

    /**
     * Envía una acción de chat (ej. typing).
     * No lanza excepción en caso de fallo.
     */
    public static function sendChatAction(string $number, string $action): ?array
    {
        $payload = [
            'number'  => $number,
            'action' => $action,
        ];

        if (\WhatsappBot\Config\WHATSAPP_INSTANCE_ID !== '') {
            $payload['whatsappId'] = \WhatsappBot\Config\WHATSAPP_INSTANCE_ID;
        }

        return self::makeRequest('/api/messages/action', $payload, false);
    }

    /**
     * Verifica si un número existe en WhatsApp.
     */
    public static function checkNumber(string $number): array
    {
        return self::makeRequest('/api/messages/check', [
            'number' => $number,
        ]);
    }

    /**
     * Configura el webhook de la instancia.
     * Errores no críticos.
     */
    public static function setWebhook(string $url): ?array
    {
        $payload = ['url' => $url];
        if (\WhatsappBot\Config\WHATSAPP_INSTANCE_ID !== '') {
            $payload['whatsappId'] = \WhatsappBot\Config\WHATSAPP_INSTANCE_ID;
        }
        return self::makeRequest('/api/messages/webhook', $payload, false);
    }

    /**
     * Obtiene información de la instancia.
     * Errores no críticos.
     */
    public static function getInstanceInfo(): ?array
    {
        $endpoint = '/api/messages/instance';
        if (\WhatsappBot\Config\WHATSAPP_INSTANCE_ID !== '') {
            $endpoint .= '?' . http_build_query([
                'whatsappId' => \WhatsappBot\Config\WHATSAPP_INSTANCE_ID,
            ]);
        }
        return self::makeRequest($endpoint, [], false);
    }

    /**
     * Realiza una petición HTTP a la instancia.
     *
     * @param string $endpoint Ruta del endpoint (con / inicial).
     * @param array  $payload  Datos a enviar en JSON.
     * @param bool   $critical Si es true, lanza excepción ante errores.
     * @return array|null      Respuesta decodificada o null en fallo no crítico.
     * @throws RuntimeException Si la petición falla en modo crítico.
     */
    private static function makeRequest(string $endpoint, array $payload = [], bool $critical = true): ?array
    {
        $baseUrl = rtrim(\WhatsappBot\Config\WHATSAPP_API_URL, '/');
        $token   = \WhatsappBot\Config\WHATSAPP_TOKEN;

        if (empty($baseUrl) || empty($token)) {
            throw new RuntimeException('WhatsApp API credentials not configured');
        }

        $url = $baseUrl . $endpoint;
        $ch  = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            if ($critical) {
                $message = $error ?: 'HTTP ' . $httpCode;
                throw new RuntimeException('Request failed: ' . $message);
            }
            return null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($critical) {
                throw new RuntimeException('Invalid JSON response');
            }
            return null;
        }

        return $decoded;
    }
}

