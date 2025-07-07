<?php
/**
 * Utilities for common link regex patterns.
 */

/**
 * Get regex patterns for detecting Netflix links.
 * @return array
 */
function getNetflixLinkPatterns() {
    return [
        // Generic pattern used in UnifiedQueryEngine
        '/(https?:\\/\\/[^\\s\\)\\]]+netflix\\.com[^\\s\\)\\]]*(?:travel\\/verify|account\\/travel|verify)[^\\s\\)\\]]*)/i',
        // Netflix Travel Verify - Highest priority
        '/(https?:\\/\\/(?:www\\.)?netflix\\.com\\/account\\/travel\\/verify[^\\s\\)]*)/i',
        // Netflix Account Access general
        '/(https?:\\/\\/(?:www\\.)?netflix\\.com\\/account\\/[^\\s\\)]*(?:verify|access|travel)[^\\s\\)]*)/i',
        // Netflix Manage Account Access
        '/(https?:\\/\\/(?:www\\.)?netflix\\.com\\/ManageAccountAccess[^\\s\\)]*)/i',
        // Netflix Password Reset
        '/(https?:\\/\\/(?:www\\.)?netflix\\.com\\/password[^\\s\\)]*)/i',
        // HTML links
        '/href=["\']([^"\']*netflix\\.com\\/account\\/travel\\/verify[^"\']*)["\']/',
        '/href=["\']([^"\']*netflix\\.com\\/account[^"\']*(?:verify|access|travel)[^"\']*)["\']/',
    ];
}

/**
 * Detect the first Netflix link in a text.
 * Returns an associative array with 'enlace' and 'posicion' or null.
 */
function detectNetflixLink(string $text) {
    $patterns = getNetflixLinkPatterns();
    foreach ($patterns as $i => $pattern) {
        if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $link = $matches[1][0];
            $pos  = $matches[1][1];
            // Trim brackets and quotes like the original logic
            $link = trim($link, "\"'<>()[]");
            $link = html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return ['enlace' => $link, 'posicion' => $pos, 'patron' => $i];
        }
    }
    return null;
}
