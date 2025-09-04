<?php
namespace Shared;

class WhatsAppUrlHelper
{
    /**
     * Sanitizes a provided WhatsApp API base URL, removing unwanted paths
     * like /messages/send and any additional segments. Returns the clean base
     * URL and sets a warning message when modifications are made.
     */
    public static function sanitizeBaseUrl(string $url, ?string &$warning = null): string
    {
        $warning = null;
        if ($url === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
            $warning = 'URL base no válida';
            return $url;
        }

        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        if (!empty($parts['path']) && $parts['path'] !== '/') {
            if (strpos($parts['path'], '/messages/send') !== false) {
                $warning = 'Se removió /messages/send de la URL base';
            } else {
                $warning = 'La URL base contenía una ruta no válida y fue recortada';
            }
        }

        return $base;
    }
}
