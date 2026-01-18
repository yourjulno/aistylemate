<?php
/**
 * FILE: /var/www/u3380884/data/www/aistylemate.ru/api/submit.php
 *
 * Expects multipart/form-data:
 * - email (string)
 * - face (file image)
 * - full (file image)
 *
 * Returns JSON:
 * { ok: true, result: { type: string, reason: string, bullets: string[] } }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

error_reporting(E_ALL);
ini_set('display_errors', '0');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  out(405, ['ok' => false, 'error' => 'Method Not Allowed']);
}

$dbConfigPath = '/var/www/u3380884/data/www/_private/db.php';
$openaiConfigPath = '/var/www/u3380884/data/www/_private/openai.php';

if (!is_file($dbConfigPath)) {
  out(500, ['ok' => false, 'error' => 'Конфиг БД не найден', 'path' => $dbConfigPath]);
}
if (!is_file($openaiConfigPath)) {
  out(500, ['ok' => false, 'error' => 'Конфиг OpenAI не найден', 'path' => $openaiConfigPath]);
}

$dbCfg = require $dbConfigPath;
$openaiCfg = require $openaiConfigPath;

$apiKey = trim((string)($openaiCfg['OPENAI_API_KEY'] ?? ''));
if ($apiKey === '' || $apiKey === '...' || stripos($apiKey, 'PASTE') !== false) {
  out(500, ['ok' => false, 'error' => 'OPENAI_API_KEY не задан']);
}

$proxyUrl = trim((string)($openaiCfg['OPENAI_PROXY_URL'] ?? ''));
if ($proxyUrl === '') {
  out(500, ['ok' => false, 'error' => 'OPENAI_PROXY_URL не задан в _private/openai.php']);
}

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  out(400, ['ok' => false, 'error' => 'Некорректный email']);
}

function require_image(string $key): array {
  if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
    out(400, ['ok' => false, 'error' => "Файл '$key' не найден"]);
  }
  $f = $_FILES[$key];

  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    out(400, ['ok' => false, 'error' => "Ошибка загрузки '$key'"]);
  }

  $max = 6 * 1024 * 1024;
  if (($f['size'] ?? 0) <= 0 || $f['size'] > $max) {
    out(400, ['ok' => false, 'error' => "Файл '$key' слишком большой (max 6MB)"]);
  }

  $tmp = (string)($f['tmp_name'] ?? '');
  $mime = mime_content_type($tmp) ?: '';
  $allowed = ['image/jpeg', 'image/png', 'image/webp'];
  if (!in_array($mime, $allowed, true)) {
    out(400, ['ok' => false, 'error' => "Неподдерживаемый формат '$key' ($mime)"]);
  }

  $bin = file_get_contents($tmp);
  if ($bin === false) {
    out(500, ['ok' => false, 'error' => "Не удалось прочитать '$key'"]);
  }

  return [
    'mime' => $mime,
    'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bin),
  ];
}

function try_parse_json_from_text(string $text): ?array {
  $text = trim($text);
  if ($text === '') return null;

  $direct = json_decode($text, true);
  if (is_array($direct)) return $direct;

  if (preg_match('/\{[\s\S]*\}/u', $text, $m) === 1) {
    $ex = json_decode($m[0], true);
    if (is_array($ex)) return $ex;
  }

  return null;
}

function normalize_result(array $j): ?array {
  $type = isset($j['type']) ? trim((string)$j['type']) : '';
  $reason = isset($j['reason']) ? trim((string)$j['reason']) : '';
  $bullets = isset($j['bullets']) && is_array($j['bullets']) ? $j['bullets'] : [];

  $bullets = array_values(array_filter(array_map(
    fn($x) => trim((string)$x),
    $bullets
  ), fn($x) => $x !== ''));

  $bullets = array_slice($bullets, 0, 4);
  while (count($bullets) < 4) $bullets[] = '—';

  if ($type === '' || $reason === '') return null;

  return ['type' => $type, 'reason' => $reason, 'bullets' => $bullets];
}

$face = require_image('face');
$full = require_image('full');

/** 1) Сохраняем email */
try {
  $dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    (string)$dbCfg['host'],
    (string)$dbCfg['dbname'],
    (string)($dbCfg['charset'] ?? 'utf8mb4')
  );

  $pdo = new PDO($dsn, (string)$dbCfg['user'], (string)$dbCfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);

  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

  $stmt = $pdo->prepare("
    INSERT INTO waitlist_emails (email, ip_address, user_agent)
    VALUES (:email, :ip, :ua)
    ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([
    ':email' => strtolower($email),
    ':ip' => $ip,
    ':ua' => $ua,
  ]);
} catch (Throwable $e) {
  // не валим весь запрос
}

/** 2) Запрос в OpenAI через прокси (Worker) */
$prompt = <<<TXT
Ты — эксперт по "типажам внешности из TikTok" (вайб-архетипы).
На входе 2 фото: (1) лицо, (2) полный рост.

Задача:
- Выбери РОВНО ОДИН типаж (короткое название на русском, пример: "Луна", "Солнце", "Лёд", "Муза", "Нимфа", "Дива").
- Объясни почему (1–2 предложения: черты, контраст, линии/силуэт).
- Дай 4 коротких признака (2–5 слов каждый).

Верни СТРОГО JSON. Без пояснений, без Markdown, без ```.
Формат:
{"type":"...","reason":"...","bullets":["...","...","...","..."]}
TXT;

$payload = [
  'model' => $openaiCfg['DEFAULT_MODEL'] ?? 'gpt-4.1-mini',
  'input' => [[
    'role' => 'user',
    'content' => [
      ['type' => 'input_text',  'text' => $prompt],
      ['type' => 'input_image', 'image_url' => $face['data_url']],
      ['type' => 'input_image', 'image_url' => $full['data_url']],
    ],
  ]],
];

$ch = curl_init($proxyUrl);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey, // если Worker валидирует/проксирует
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 90,
]);

$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false) {
  out(500, ['ok' => false, 'error' => 'Proxy curl error', 'debug' => $err]);
}

$worker = json_decode($raw, true);
if ($code < 200 || $code >= 300) {
  out(502, ['ok' => false, 'error' => 'Proxy/OpenAI error', 'debug' => $worker ?: mb_substr($raw, 0, 400)]);
}

if (!is_array($worker) || !($worker['ok'] ?? false)) {
  out(502, ['ok' => false, 'error' => 'Worker вернул неожиданный формат', 'debug' => $worker ?: mb_substr($raw, 0, 400)]);
}

/**
 * Worker может вернуть:
 * 1) { ok:true, result:{type,reason,bullets} }
 * 2) { ok:true, aiText:"{...json...}" }
 */
$result = null;

if (isset($worker['result']) && is_array($worker['result'])) {
  $result = normalize_result($worker['result']);
}

if (!$result && isset($worker['aiText']) && is_string($worker['aiText'])) {
  $parsed = try_parse_json_from_text($worker['aiText']);
  if (is_array($parsed)) $result = normalize_result($parsed);
}

if (!$result) {
  out(502, [
    'ok' => false,
    'error' => 'AI вернул невалидный JSON',
    'aiTextPreview' => isset($worker['aiText']) ? mb_substr((string)$worker['aiText'], 0, 400) : null,
    'debug' => $worker,
  ]);
}

out(200, ['ok' => true, 'result' => $result]);
