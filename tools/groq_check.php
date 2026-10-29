<?php
// tools/groq_check.php — sinusubukan ang koneksyon sa Groq at ipinapakita ang eksaktong error. CLI lang:
//     php tools/groq_check.php
// Binabasa ang GROQ_API_KEY at GROQ_MODEL mula sa .env. HINDI nililimbag ang key.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/env_loader.php';

$key   = trim((string)env('GROQ_API_KEY', ''));
$model = trim((string)env('GROQ_MODEL', '')) ?: 'openai/gpt-oss-120b';
$ok    = true;

function groq_call(string $method, string $path, string $key, ?array $body = null): array
{
    $ch = curl_init('https://api.groq.com/openai/v1' . $path);
    $headers = ['Authorization: Bearer ' . $key];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $err = $raw === false ? 'curl error ' . curl_errno($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, json_decode((string)$raw, true), $err];
}

function line(bool $pass, string $text): void
{
    echo ($pass ? '[ OK ] ' : '[FAIL] ') . $text . "\n";
}

echo "Groq connection check\n---------------------\n";

if ($key === '') {
    line(false, 'GROQ_API_KEY is empty. Add it to the .env file.');
    exit(1);
}
line(true, 'API key found (' . strlen($key) . ' characters, starts with "' . substr($key, 0, 4) . '").');
if (preg_match('/[\s"\']/', (string)env('GROQ_API_KEY', ''))) {
    line(false, 'The key contains a space or quote. Remove it in .env.');
    $ok = false;
}

[$status, $body, $err] = groq_call('GET', '/models', $key);
if ($err) {
    line(false, "Could not reach api.groq.com ({$err}). Check the internet connection or a firewall.");
    exit(1);
}
if ($status === 401 || $status === 403) {
    line(false, 'Groq rejected the key (HTTP ' . $status . '). Create a new key at console.groq.com.');
    exit(1);
}
if ($status !== 200 || !isset($body['data'])) {
    line(false, "Unexpected answer from Groq (HTTP {$status}).");
    exit(1);
}
$ids = array_column($body['data'], 'id');
sort($ids);
line(true, 'Key accepted. Models available to you now: ' . implode(', ', $ids));

if (!in_array($model, $ids, true)) {
    line(false, "GROQ_MODEL \"{$model}\" is not in that list. Set GROQ_MODEL in .env to one of them (a chat model that supports tools, e.g. openai/gpt-oss-120b).");
    exit(1);
}
line(true, "GROQ_MODEL \"{$model}\" is available.");

[$status, $body, $err] = groq_call('POST', '/chat/completions', $key, ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'Reply with the word OK.']], 'max_tokens' => 20]);
if ($status === 200) {
    $used = (int)($body['usage']['total_tokens'] ?? 0);
    line(true, "Test question answered ({$used} tokens). The Copilot should work.");
} elseif ($status === 429) {
    line(false, 'The free limit was reached for now (HTTP 429). Wait a minute and try again.');
    $ok = false;
} else {
    line(false, "Test question failed (HTTP {$status}): " . ($body['error']['code'] ?? '') . ' ' . ($body['error']['message'] ?? ''));
    $ok = false;
}
exit($ok ? 0 : 1);
