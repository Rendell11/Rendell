<?php
/**
 * ai_config.php — Gemini settings for the Announcement / Disaster / Resident AI features.
 *
 * The API key is NO LONGER stored in this file. It is read only from
 * GEMINI_API_KEY in the CAPS .env (loaded by admin/config.php).
 * All calls go through admin/ai_helper.php (model fallback + thought filtering).
 * Function names are unchanged so existing callers keep working.
 */
require_once __DIR__ . '/../../ai_helper.php';

if (!defined('GEMINI_MODEL')) {
    // First model of the shared fallback chain (GEMINI_MODEL in .env wins).
    define('GEMINI_MODEL', ai_models()[0]);
}

if (!function_exists('gemini_api_key')) {
    /** Returns the configured key, or '' when it has not been set yet. */
    function gemini_api_key(): string {
        return ai_api_key();
    }
}

if (!function_exists('gemini_generate')) {
    /**
     * @return array ['success' => bool, 'text' => string, 'error' => string]
     */
    function gemini_generate(array $parts, string $systemPrompt, array $genConfig = []): array {
        // Plain-text answer unless the caller asks for JSON.
        $genConfig = array_merge(['maxOutputTokens' => 2048, 'temperature' => 0.6, 'responseMimeType' => null], $genConfig);
        $r = ai_call($parts, $systemPrompt, $genConfig);
        return ['success' => $r['ok'], 'text' => $r['text'], 'error' => $r['error']];
    }
}
