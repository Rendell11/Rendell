<?php
/**
 * ai_helper.php — one Gemini client for every AI feature in CAPS.
 *
 * - The key is read ONLY from GEMINI_API_KEY (project .env, loaded by
 *   admin/config.php). It is never hardcoded and never sent to the browser.
 * - Model fallback: GEMINI_MODEL (if set) → gemini-flash-lite-latest →
 *   gemini-flash-latest. Moves on at HTTP 0 (timeout) / 404 / 429 / 500 / 503.
 * - Joins every non-"thought" part of the answer.
 * - ai_cache_get()/ai_cache_put() store results by data snapshot (md5).
 */
require_once __DIR__ . '/config.php';

if (!function_exists('ai_api_key')) {
    function ai_api_key(): string {
        $key = trim((string)(getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '')));
        if ($key === '' || stripos($key, 'your_') === 0 || $key === 'PASTE_YOUR_GEMINI_API_KEY_HERE') return '';
        return $key;
    }
}

if (!function_exists('ai_models')) {
    function ai_models(): array {
        return array_values(array_unique(array_filter([
            getenv('GEMINI_MODEL') ?: null, 'gemini-flash-lite-latest', 'gemini-flash-latest',
        ])));
    }
}

if (!function_exists('ai_call')) {
    /**
     * @param array  $parts        Gemini content parts (text and/or inline_data)
     * @param string $systemPrompt optional system instruction
     * @param array  $genConfig    generationConfig overrides
     * @return array ['ok'=>bool, 'text'=>string, 'model'=>string, 'http'=>int, 'error'=>string]
     */
    function ai_call(array $parts, string $systemPrompt = '', array $genConfig = []): array {
        $apiKey = ai_api_key();
        if ($apiKey === '') {
            return ['ok' => false, 'text' => '', 'model' => '', 'http' => 0,
                    'error' => 'AI is not configured. Set GEMINI_API_KEY in the CAPS .env file.'];
        }
        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => array_merge([
                'responseMimeType' => 'application/json',
                'thinkingConfig'   => ['thinkingLevel' => 'low'],
                'maxOutputTokens'  => 4096,
                'temperature'      => 0.4,
            ], $genConfig),
        ];
        // Pass a key as null to drop a default (e.g. responseMimeType for plain-text answers).
        $payload['generationConfig'] = array_filter($payload['generationConfig'], fn($v) => $v !== null);
        if ($systemPrompt !== '') $payload['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];

        @set_time_limit(120);
        $models = ai_models();
        $response = ''; $httpCode = 0; $model = '';
        foreach ($models as $i => $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
                 . ':generateContent?key=' . urlencode($apiKey);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 35,
            ]);
            $response = (string)curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!in_array($httpCode, [0, 404, 429, 500, 503], true)) break;
            if ($i < count($models) - 1) usleep(500000);
        }

        $text = '';
        $data = json_decode($response, true);
        if ($httpCode === 200 && is_array($data)) {
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (empty($part['thought']) && isset($part['text'])) $text .= $part['text'];
            }
        }
        if ($httpCode !== 200 || trim($text) === '') {
            error_log('[CAPS-AI] Gemini request failed (HTTP ' . $httpCode . ', model ' . $model . ').');
            return ['ok' => false, 'text' => '', 'model' => $model, 'http' => $httpCode,
                    'error' => 'The AI service is unavailable right now. Please try again later.'];
        }
        return ['ok' => true, 'text' => trim($text), 'model' => $model, 'http' => $httpCode, 'error' => ''];
    }
}

if (!function_exists('ai_parse_json')) {
    /** Decode a JSON answer, tolerating ```json fences. */
    function ai_parse_json(string $text) {
        return json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $text)), true);
    }
}

if (!function_exists('ai_cache_file')) {
    function ai_cache_file(string $namespace, $snapshot): string {
        $ns = preg_replace('/[^a-z0-9_]/i', '', $namespace) ?: 'ai';
        return __DIR__ . '/cache/ai/' . $ns . '_' . md5(json_encode($snapshot)) . '.json';
    }
    function ai_cache_get(string $namespace, $snapshot): ?array {
        $f = ai_cache_file($namespace, $snapshot);
        $d = is_readable($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) ? $d : null;
    }
    function ai_cache_put(string $namespace, $snapshot, array $result): void {
        $f = ai_cache_file($namespace, $snapshot);
        if (is_dir(dirname($f)) || @mkdir(dirname($f), 0775, true)) {
            @file_put_contents($f, json_encode($result, JSON_UNESCAPED_UNICODE));
        }
    }
}
