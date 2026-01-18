<?php
// FILE: /www/api/submit.php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
  exit;
}

$root = dirname(__DIR__);
$dbCfg = require $root . '/_private/db.php';
$oaCfg = require $root . '/_private/openai.php';
$apiKey = $oaCfg['OPENAI_API_KEY'] ?? '';

if (!$apiKey || $apiKey === 'PASTE_YOUR_KEY_HERE') {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'OPENAI_API_KEY не задан']);
  exit;
}

$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Некорректный email']);
  exit;
}

function require_image(string $key): array {
  if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Файл '$key' не найден"]);
    exit;
  }
  $f = $_FILES[$key];

  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Ошибка загрузки '$key'"]);
    exit;
  }

  $max = 6 * 1024 * 1024; // 6MB
  if (($f['size'] ?? 0) <= 0 || $f['size'] > $max) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Файл '$key' слишком большой (max 6MB)"]);
    exit;
  }

  $tmp = $f['tmp_name'] ?? '';
  $mime = mime_content_type($tmp) ?: '';
  $allowed = ['image/jpeg', 'image/png', 'image/webp'];
  if (!in_array($mime, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Неподдерживаемый формат '$key' ($mime)"]);
    exit;
  }

  $bin = file_get_contents($tmp);
  if ($bin === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => "Не удалось прочитать '$key'"]);
    exit;
  }

  $b64 = base64_encode($bin);
  $dataUrl = "data:$mime;base64,$b64";

  return ['mime' => $mime, 'data_url' => $dataUrl];
}

$face = require_image('face');
$full = require_image('full');

/** 1) сохраняем email (можно в ту же таблицу) */
try {
  $dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $dbCfg['host'],
    $dbCfg['dbname'],
    $dbCfg['charset'] ?? 'utf8mb4'
  );
  $pdo = new PDO($dsn, $dbCfg['user'], $dbCfg['pass'], [
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
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Ошибка БД', 'debug' => $e->getMessage()]);
  exit;
}

/** 2) запрос в OpenAI (Responses API) */
$prompt = <<<TXT
Ты — персональный AI-стилист. У пользователя 2 фото: (1) лицо, (2) полный рост.
1) Определи примерный типаж/контраст/подтон и почему именно такой типаж кратко.
2) Предложи офисный образ: верх/низ/обувь/аксессуары.
3) Дай объяснение "почему подходит".
Ответ дай структурировано и очень кратко: "Типаж", "Рекомендации", "Образ", "Почему подходит".
TXT;

$payload = [
  'model' => 'gpt-4.1-mini', // поменяй при желании
  'input' => [[
    'role' => 'user',
    'content' => [
      ['type' => 'input_text', 'text' => $prompt],
      ['type' => 'input_image', 'image_url' => $face['data_url']],
      ['type' => 'input_image', 'image_url' => $full['data_url']],
    ],
  ]],
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 60,
]);

$raw = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'OpenAI curl error', 'debug' => $err]);
  exit;
}

$json = json_decode($raw, true);
if ($code < 200 || $code >= 300) {
  http_response_code(502);
  echo json_encode(['ok' => false, 'error' => 'OpenAI error', 'debug' => $json ?: $raw]);
  exit;
}

/**
 * В Responses API текст обычно лежит в output[*].content[*].text
 * (точная структура зависит от типа ответа).
 * Мы соберем весь output_text в одну строку.
 */
$aiText = '';
if (isset($json['output']) && is_array($json['output'])) {
  foreach ($json['output'] as $out) {
    if (!isset($out['content']) || !is_array($out['content'])) continue;
    foreach ($out['content'] as $c) {
      if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
        $aiText .= $c['text'] . "\n";
      }
    }
  }
}
$aiText = trim($aiText);

echo json_encode([
  'ok' => true,
  'aiText' => $aiText ?: '(пустой ответ)',
]);
