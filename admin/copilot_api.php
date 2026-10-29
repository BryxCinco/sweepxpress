<?php
// admin/copilot_api.php — JSON endpoint ng Admin Copilot (Phase 8e-2). Ang widget (8e-3) ang tumatawag dito.
// Admin lang, POST lang, may CSRF, may limit (bawat admin kada minuto at kada araw, at pangkalahatan kada araw).
// Ang tanong at ang mga tool na ginamit ay itinatala sa copilot_log; ang sagot ng AI ay hindi.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_products.php';   // sx_csrf_token
require_once __DIR__ . '/../includes/groq_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sx_cp_out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Ilang tala sa rate_limits sa huling $seconds. "Fail closed": kung hindi mabasa ang table, ituring na puno na. */
function sx_cp_used(PDO $pdo, string $action, string $key, int $seconds): int
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = ? AND rl_key = ? AND created_at > ?");
        $st->execute([$action, $key, date('Y-m-d H:i:s', time() - $seconds)]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        error_log('copilot limit check failed: ' . $e->getMessage());
        return PHP_INT_MAX;
    }
}

function sx_cp_log(PDO $pdo, int $userId, string $question, array $tools, array $meta): void
{
    try {
        $pdo->prepare("INSERT INTO copilot_log (user_id, question, tools, prompt_tokens, completion_tokens, ms, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$userId ?: null, mb_substr($question, 0, 500), mb_substr(implode(',', $tools), 0, 255) ?: null,
                       (int)($meta['prompt_tokens'] ?? 0), (int)($meta['completion_tokens'] ?? 0), (int)($meta['ms'] ?? 0), (string)$meta['status']]);
    } catch (PDOException $e) {
        error_log('copilot log failed: ' . $e->getMessage());   // hindi dapat pigilan ang sagot dahil lang sa log
    }
}

// ---------------------------------------------------------------------------------
if (!is_admin()) sx_cp_out(403, ['error' => 'forbidden', 'message' => 'Admin access only.']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sx_cp_out(405, ['error' => 'method', 'message' => 'Use POST.']);
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    sx_cp_out(403, ['error' => 'csrf', 'message' => 'Your session expired. Refresh the page and try again.']);
}

$body = json_decode((string)file_get_contents('php://input', false, null, 0, 16385), true);
$question = is_array($body) && is_string($body['question'] ?? null) ? trim($body['question']) : '';
if ($question === '' || mb_strlen($question) > SX_COPILOT_MAX_QUESTION) {
    sx_cp_out(400, ['error' => 'question', 'message' => 'Type a question of up to ' . SX_COPILOT_MAX_QUESTION . ' characters.']);
}
if (!sx_groq_configured()) {
    sx_cp_out(503, ['error' => 'not_configured', 'message' => 'The AI assistant is not set up yet. Add GROQ_API_KEY to the .env file.']);
}

$userId   = (int)($_SESSION['user']['id'] ?? 0);
$perMin   = max(1, (int)env('COPILOT_MINUTE_LIMIT', 3));
$perDay   = max(1, (int)env('COPILOT_DAILY_LIMIT', 100));
$allDay   = max(1, (int)env('COPILOT_GLOBAL_DAILY_LIMIT', 300));
$key      = 'u' . $userId;
$usedDay  = sx_cp_used($pdo, 'copilot', $key, 86400);

if (sx_cp_used($pdo, 'copilot', $key, 60) >= $perMin) {
    sx_cp_log($pdo, $userId, $question, [], ['status' => 'limited']);
    sx_cp_out(429, ['error' => 'rate_minute', 'message' => 'Too many questions in a minute. Wait a little and try again.', 'retry_after' => 60]);
}
if ($usedDay >= $perDay) {
    sx_cp_log($pdo, $userId, $question, [], ['status' => 'limited']);
    sx_cp_out(429, ['error' => 'rate_day', 'message' => "You reached today's limit of {$perDay} questions. It resets within 24 hours."]);
}
if (sx_cp_used($pdo, 'copilot', 'all', 86400) >= $allDay) {
    sx_cp_log($pdo, $userId, $question, [], ['status' => 'limited']);
    sx_cp_out(429, ['error' => 'rate_global', 'message' => "The assistant's daily limit for all admins was reached. Try again tomorrow."]);
}
// Itala bago tumawag sa Groq, para bilang pa rin ang mga nabigong tawag
rate_limit_record($pdo, 'copilot', $key);
rate_limit_record($pdo, 'copilot', 'all');

$started = microtime(true);
try {
    $res = sx_copilot_chat($pdo, $body['history'] ?? [], $question);
    $ms = (int)round((microtime(true) - $started) * 1000);
    sx_cp_log($pdo, $userId, $question, $res['tools'], ['status' => 'ok', 'ms' => $ms] + $res);
    sx_cp_out(200, ['reply' => $res['reply'], 'tools' => $res['tools'], 'remaining_today' => max(0, $perDay - $usedDay - 1)]);
} catch (SxGroqException $e) {
    $ms = (int)round((microtime(true) - $started) * 1000);
    error_log("copilot error [{$e->kind}]: " . $e->getMessage());   // walang API key sa mensahe
    sx_cp_log($pdo, $userId, $question, [], ['status' => 'error', 'ms' => $ms]);
    [$code, $msg] = match ($e->kind) {
        'model'   => [502, 'The AI model "' . sx_groq_model() . '" is not available on Groq. Set GROQ_MODEL in .env to a current model (console.groq.com/docs/models).'],
        'auth'    => [502, 'The AI service did not accept the API key. Check GROQ_API_KEY in .env.'],
        'rate'    => [429, 'The free AI limit was reached for now. Try again in ' . max(5, (int)$e->retryAfter) . ' seconds.'],
        'network' => [504, 'Could not reach the AI service. Check the internet connection of the server.'],
        'bad_tool' => [502, 'The assistant got confused. Please ask again in a different way.'],
        'budget'  => [504, 'That question took too long. Try a simpler one.'],
        default   => [502, 'The AI service had a problem. Please try again.'],
    };
    sx_cp_out($code, ['error' => $e->kind, 'message' => $msg] + ($e->kind === 'rate' ? ['retry_after' => max(5, (int)$e->retryAfter)] : []));
} catch (Throwable $e) {
    error_log('copilot failed: ' . $e->getMessage());
    sx_cp_log($pdo, $userId, $question, [], ['status' => 'error']);
    sx_cp_out(500, ['error' => 'server', 'message' => 'Something went wrong. Please try again.']);
}
