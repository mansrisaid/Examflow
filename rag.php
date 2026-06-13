<?php
/**
 * rag.php — RAG API بدون framework
 * ارفعه على Apache وشغّله مباشرة
 * لا composer، لا vendor، لا إعداد
 */

// ══════════════════════════════════════════════════════
//  CONFIG — عدّل هذه القيم فقط
// ══════════════════════════════════════════════════════
define('JINA_KEY',        'jina_5a911e32a68a46b2b1d1ef38906bf8fcLzgVd_pDXAjMwSgKhOwDnRyqAXVq');
define('QDRANT_URL',      'https://5922efd0-8b1d-4594-9f4a-7d0377803d84.sa-east-1-0.aws.cloud.qdrant.io');
define('QDRANT_KEY',      'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhY2Nlc3MiOiJtIiwic3ViamVjdCI6ImFwaS1rZXk6ZGFmYTA5NjktNWZiNC00ZWE5LWE5YmYtNDAxMjQ5ZTA0ZTMxIn0.NsF0Rt7teQ78kavQy4qXec6LsxktcUwD3JJX-u55xBc');
define('QDRANT_COL',      '4am_mathematics');
define('DEEPSEEK_KEY',    'sk-f3b32361d96643878618f5224f981f8a');
define('DEEPSEEK_MODEL',  'deepseek-chat');
define('DAILY_TOKENS',    12000);
define('DAILY_REQUESTS',  10);
define('CACHE_DIR',       __DIR__ . '/rag_cache'); // مجلد الكاش — يُنشأ تلقائياً

// ══════════════════════════════════════════════════════
//  LOGGING
// ══════════════════════════════════════════════════════
define('LOG_FILE', __DIR__ . '/rag_log/rag.log');

function rag_log(string $level, string $msg, array $ctx = []): void
{
    $dir = dirname(LOG_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $line = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $msg,
        $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
    );
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ══════════════════════════════════════════════════════
//  CORS & ROUTING
// ══════════════════════════════════════════════════════
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// إنشاء مجلد الكاش إذا لم يكن موجوداً
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// تحليل الطلب — يدعم طريقتين:
// 1. ?action=ask  (الأبسط، يعمل بدون .htaccess)
// 2. /rag.php/ask (يحتاج .htaccess)
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$ip     = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
$ip     = explode(',', $ip)[0];

// استخراج الـ action
$action = '';
if (isset($_GET['action'])) {
    // الطريقة 1: ?action=ask
    $action = $_GET['action'];
} else {
    // الطريقة 2: /rag.php/ask
    $path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path   = rtrim(str_replace('/rag.php', '', $path), '/');
    $action = ltrim($path, '/');
}

// ── Router ────────────────────────────────────────────
match (true) {
    $action === 'health' => route_health(),
    $action === 'usage'  => route_usage($ip),
    $action === 'search' && $method === 'POST' => route_search($body),
    $action === 'ask'    && $method === 'POST' => route_ask($body, $ip),
    $action === 'logs'   => route_logs(),
    $action === 'debug'  => json_out([
        'action'  => $action,
        'method'  => $method,
        'body'    => $body,
        'get'     => $_GET,
        'uri'     => $_SERVER['REQUEST_URI'],
        'deepseek_key_set' => !empty(DEEPSEEK_KEY) && DEEPSEEK_KEY !== 'sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'jina_key_set'     => !empty(JINA_KEY)     && JINA_KEY     !== 'jina_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'qdrant_key_set'   => !empty(QDRANT_KEY)   && QDRANT_KEY   !== 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    ]),
    default => json_out(['error' => 'Not found. Use ?action=health|usage|search|ask'], 404),
};

// ══════════════════════════════════════════════════════
//  ROUTES
// ══════════════════════════════════════════════════════

function route_health(): void
{
    $res = qdrant_get('/collections/' . QDRANT_COL);
    json_out([
        'status'     => 'ok',
        'collection' => QDRANT_COL,
        'points'     => $res['result']['points_count'] ?? 0,
        'php'        => PHP_VERSION,
    ]);
}

function route_usage(string $ip): void
{
    json_out(get_usage($ip));
}

function route_search(array $body): void
{
    $query      = trim($body['query'] ?? '');
    $limit      = min(20, max(1, (int)($body['limit'] ?? 5)));
    $filterType = $body['filter_type'] ?? null;
    $mode       = $body['search_mode'] ?? 'hybrid';

    if (!$query) {
        json_out(['error' => 'query is required'], 422);
        return;
    }

    $t0     = microtime(true);
    $vector = jina_embed($query);
    $results = qdrant_search($vector, $limit, $filterType, $mode);

    json_out([
        'query'   => $query,
        'results' => $results,
        'count'   => count($results),
        'mode'    => $mode,
        'time_ms' => round((microtime(true) - $t0) * 1000, 1),
    ]);
}

function route_ask(array $body, string $ip): void
{
    $query      = trim($body['query'] ?? '');
    $limit      = min(10, max(1, (int)($body['limit'] ?? 5)));
    $ansMode    = $body['answer_mode'] ?? 'direct';
    $srchMode   = $body['search_mode'] ?? 'hybrid';
    $filterType = $body['filter_type'] ?? null;

    if (!$query) {
        sse_send('error', ['message' => 'query is required']);
        return;
    }

    rag_log('info', 'ask request', ['ip' => $ip, 'query' => substr($query, 0, 80), 'mode' => $ansMode]);

    // تحقق من الحد اليومي
    if (!check_and_increment($ip)) {
        $usage = get_usage($ip);
        sse_start();
        sse_send('error', [
            'message' => 'وصلت للحد اليومي (' . DAILY_TOKENS . ' token / ' . DAILY_REQUESTS . ' طلب). يعود الحد غداً.',
            'usage'   => $usage,
        ]);
        return;
    }

    sse_start();

    // 1. Embedding + Search
    try {
        $vector  = jina_embed($query);
        $results = qdrant_search($vector, $limit, $filterType, $srchMode);
    } catch (\Exception $e) {
        rag_log('error', 'Jina/Qdrant failed', ['error' => $e->getMessage()]);
        sse_send('error', ['message' => 'Jina/Qdrant: ' . $e->getMessage()]);
        return;
    }

    // 2. أرسل نتائج البحث
    sse_send('meta', [
        'results'     => $results,
        'count'       => count($results),
        'search_mode' => $srchMode,
        'answer_mode' => $ansMode,
    ]);

    // 3. بناء الـ prompt
    [$system, $user] = build_prompt($query, $results, $ansMode);

    // 4. DeepSeek streaming عبر cURL
    $usageData = [];

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'       => DEEPSEEK_MODEL,
            'stream'      => true,
            'temperature' => 0.3,
            'max_tokens'  => 1500,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ]),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$usageData) {
            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $chunk = substr($line, 6);
                if ($chunk === '[DONE]') continue;
                $d = json_decode($chunk, true);
                if (!$d) continue;
                $delta = $d['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    sse_send('token', ['text' => $delta]);
                }
                if (!empty($d['usage'])) {
                    $usageData = $d['usage'];
                }
            }
            return strlen($data);
        },
        CURLOPT_TIMEOUT => 90,
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        rag_log('error', 'DeepSeek failed', ['http_code' => $httpCode]);
        sse_send('error', ['message' => "DeepSeek HTTP $httpCode"]);
        return;
    }

    // 5. سجّل الـ tokens وأرسل الإحصائيات
    $total = $usageData['total_tokens'] ?? 0;
    if ($total > 0) add_tokens($ip, $total);
    rag_log('info', 'ask completed', ['tokens' => $total, 'ip' => $ip]);

    $usageData['daily'] = get_usage($ip);
    sse_send('usage', $usageData);
    sse_send('done',  []);
}

// ══════════════════════════════════════════════════════
//  JINA
// ══════════════════════════════════════════════════════
function jina_embed(string $text): array
{
    $res = http_post('https://api.jina.ai/v1/embeddings', [
        'model'          => 'jina-embeddings-v3',
        'input'          => [$text],
        'normalized'     => true,
        'embedding_type' => 'float',
    ], ['Authorization: Bearer ' . JINA_KEY]);

    if (!isset($res['data'][0]['embedding'])) {
        throw new \Exception('Jina: ' . json_encode($res));
    }
    return $res['data'][0]['embedding'];
}

// ══════════════════════════════════════════════════════
//  QDRANT
// ══════════════════════════════════════════════════════
function qdrant_get(string $path): array
{
    $ch = curl_init(QDRANT_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['api-key: ' . QDRANT_KEY, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?? [];
}

function qdrant_search(array $vector, int $limit, ?string $filterType, string $mode): array
{
    $url = QDRANT_URL . '/collections/' . QDRANT_COL . '/points/query';

    $body = [
        'limit'        => $limit,
        'with_payload' => true,
        'with_vectors' => false,
    ];

    if ($mode === 'hybrid') {
        $body['prefetch'] = [
            ['query' => $vector, 'using' => 'content',        'limit' => $limit * 2],
            ['query' => $vector, 'using' => 'content-sparse',  'limit' => $limit * 2],
        ];
        $body['query'] = ['fusion' => 'rrf'];
    } else {
        $body['query'] = $vector;
        $body['using'] = 'content';
    }

    if ($filterType) {
        $body['filter'] = [
            'must' => [['key' => 'type', 'match' => ['value' => $filterType]]]
        ];
    }

    $res = http_post($url, $body, ['api-key: ' . QDRANT_KEY]);
    $points = $res['result']['points'] ?? [];

    return array_map(fn($p) => [
        'id'        => $p['id'],
        'score'     => round($p['score'], 4),
        'type'      => $p['payload']['type']      ?? '',
        'title'     => $p['payload']['title']     ?? '',
        'content'   => $p['payload']['content']   ?? '',
        'chapter'   => $p['payload']['chapter']   ?? '',
        'keywords'  => $p['payload']['keywords']  ?? [],
        'has_image' => $p['payload']['has_image'] ?? false,
        'image_url' => $p['payload']['image_url'] ?? null,
    ], $points);
}

// ══════════════════════════════════════════════════════
//  PROMPTS
// ══════════════════════════════════════════════════════
function build_prompt(string $query, array $results, string $mode): array
{
    $ctx = '';
    foreach ($results as $i => $r) {
        $ctx .= "\n[" . ($i + 1) . "] {$r['title']}\nالنوع: {$r['type']}\n{$r['content']}\n";
    }

    $latex = ' مهم: اكتب كل المعادلات والأرقام الرياضية داخل $...$ مثل $x=-9$. '
           . 'استخدم $$...$$ للمعادلات المستقلة. '
           . 'الكسور: \frac{a}{b}، الأسس: x^2، الجذور: \sqrt{x}.';

    $s = [
        'direct'     => 'أنت مساعد رياضيات للرابعة متوسط الجزائري. أجب مباشرة بالعربية.' . $latex,
        'sourced'    => 'أنت مساعد رياضيات. أجب بالعربية مع إشارات [1][2]...' . $latex,
        'stepbystep' => 'أنت أستاذ رياضيات. اشرح خطوة بخطوة بالعربية.' . $latex,
    ];
    $u = [
        'direct'     => "السؤال: $query\n\nالمعلومات:\n$ctx\n\nأجب مباشرة.",
        'sourced'    => "السؤال: $query\n\nالمصادر:\n$ctx\n\nأجب مع إشارات للمصادر.",
        'stepbystep' => "السؤال: $query\n\nالمعلومات:\n$ctx\n\nاشرح خطوة بخطوة.",
    ];

    return [$s[$mode] ?? $s['direct'], $u[$mode] ?? $u['direct']];
}

// ══════════════════════════════════════════════════════
//  DAILY USAGE — file-based cache
// ══════════════════════════════════════════════════════
function cache_file(string $ip): string
{
    return CACHE_DIR . '/' . date('Y-m-d') . '_' . md5($ip) . '.json';
}

function load_usage(string $ip): array
{
    $file = cache_file($ip);
    if (!file_exists($file)) return ['tokens' => 0, 'requests' => 0];
    return json_decode(file_get_contents($file), true) ?? ['tokens' => 0, 'requests' => 0];
}

function save_usage(string $ip, array $data): void
{
    file_put_contents(cache_file($ip), json_encode($data));
}

function get_usage(string $ip): array
{
    $d   = load_usage($ip);
    $t   = $d['tokens'];
    $r   = $d['requests'];
    $pct = round($t / DAILY_TOKENS * 100, 1);
    return [
        'tokens_used'    => $t,
        'tokens_limit'   => DAILY_TOKENS,
        'tokens_left'    => max(0, DAILY_TOKENS   - $t),
        'tokens_pct'     => round($pct, 1),
        'requests_used'  => $r,
        'requests_limit' => DAILY_REQUESTS,
        'requests_left'  => max(0, DAILY_REQUESTS - $r),
        'date'           => date('Y-m-d'),
        'warning'        => $pct >= 80,
        'exceeded'       => $t >= DAILY_TOKENS || $r >= DAILY_REQUESTS,
    ];
}

function check_and_increment(string $ip): bool
{
    $d = load_usage($ip);
    if ($d['tokens'] >= DAILY_TOKENS || $d['requests'] >= DAILY_REQUESTS) return false;
    $d['requests']++;
    save_usage($ip, $d);
    return true;
}

function add_tokens(string $ip, int $n): void
{
    $d = load_usage($ip);
    $d['tokens'] += $n;
    save_usage($ip, $d);
}

// ══════════════════════════════════════════════════════
//  HTTP HELPERS
// ══════════════════════════════════════════════════════
function http_post(string $url, array $body, array $extraHeaders = []): array
{
    $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new \Exception("cURL: $err");
    return json_decode($res, true) ?? [];
}

// ══════════════════════════════════════════════════════
//  LOG VIEWER
// ══════════════════════════════════════════════════════
function route_logs(): void
{
    $lines = 50; // آخر 50 سطر
    if (!file_exists(LOG_FILE)) {
        json_out(['logs' => [], 'message' => 'No logs yet']);
        return;
    }
    $all  = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last = array_slice($all, -$lines);
    json_out([
        'file'  => LOG_FILE,
        'total' => count($all),
        'last'  => $last,
    ]);
}

// ══════════════════════════════════════════════════════
//  SSE & JSON OUTPUT
// ══════════════════════════════════════════════════════
function sse_start(): void
{
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    // تعطيل output buffering
    if (ob_get_level()) ob_end_flush();
    set_time_limit(120);
}

function sse_send(string $type, array $data): void
{
    $payload = array_merge(['type' => $type], $data);
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
