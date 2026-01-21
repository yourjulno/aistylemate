<?php
// FILE: /var/www/u3380884/data/www/aistylemate.ru/api/outfits.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// Путь для лога (убедись, что папка writable)
$__logFile = __DIR__ . '/outfits_error.log';
@ini_set('error_log', $__logFile);

// Генерация может занимать долго
@set_time_limit(300);
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function __log(string $msg, array $ctx = []): void {
  $line = '[' . date('c') . '] ' . $msg;
  if ($ctx) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  error_log($line);
}

// Ловим warning/notice как исключение (чтобы вернуть JSON)
set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

// Ловим исключения
set_exception_handler(function(Throwable $e) use ($__logFile): void {
  __log('UNCAUGHT_EXCEPTION', [
    'type' => get_class($e),
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ]);
  out(500, [
    'ok' => false,
    'error' => 'Internal Server Error',
    'hint' => 'Смотри лог: ' . basename($GLOBALS['__logFile'] ?? 'outfits_error.log'),
  ]);
});

// Ловим fatal errors (которые обычно дают “пустой 500”)
register_shutdown_function(function() use ($__logFile): void {
  $err = error_get_last();
  if (!$err) return;

  __log('FATAL', $err);
  if (!headers_sent()) {
    out(500, [
      'ok' => false,
      'error' => 'Fatal error',
      'hint' => 'Смотри лог: ' . basename($__logFile),
    ]);
  }
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'Method Not Allowed']);

$openaiConfigPath = '/var/www/u3380884/data/www/_private/openai.php';
if (!is_file($openaiConfigPath)) out(500, ['ok' => false, 'error' => 'Конфиг OpenAI не найден', 'path' => $openaiConfigPath]);

$openaiCfg = require $openaiConfigPath;

$apiKey = trim((string)($openaiCfg['OPENAI_API_KEY'] ?? ''));
if ($apiKey === '' || $apiKey === '...' || stripos($apiKey, 'PASTE') !== false) out(500, ['ok' => false, 'error' => 'OPENAI_API_KEY не задан']);

$proxyUrl = trim((string)($openaiCfg['OPENAI_PROXY_URL'] ?? ''));
if ($proxyUrl === '') out(500, ['ok' => false, 'error' => 'OPENAI_PROXY_URL не задан в _private/openai.php']);

$event = trim((string)($_POST['event'] ?? ''));
$archetypeRaw = (string)($_POST['archetype'] ?? '');

if ($event === '' || mb_strlen($event) > 80) out(400, ['ok' => false, 'error' => 'Некорректное мероприятие']);

$archetype = json_decode($archetypeRaw, true);
if (!is_array($archetype)) out(400, ['ok' => false, 'error' => 'Некорректный archetype JSON']);

$type = trim((string)($archetype['type'] ?? ''));
$reason = trim((string)($archetype['reason'] ?? ''));
$bullets = is_array($archetype['bullets'] ?? null) ? $archetype['bullets'] : [];
if ($type === '' || $reason === '') out(400, ['ok' => false, 'error' => 'Пустой типаж']);

function require_image(string $key): array {
  if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) out(400, ['ok' => false, 'error' => "Файл '$key' не найден"]);
  $f = $_FILES[$key];
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) out(400, ['ok' => false, 'error' => "Ошибка загрузки '$key'"]);

  $max = 6 * 1024 * 1024;
  if (($f['size'] ?? 0) <= 0 || $f['size'] > $max) out(400, ['ok' => false, 'error' => "Файл '$key' слишком большой (max 6MB)"]);

  $tmp = (string)($f['tmp_name'] ?? '');
  $mime = mime_content_type($tmp) ?: '';
  $allowed = ['image/jpeg', 'image/png', 'image/webp'];
  if (!in_array($mime, $allowed, true)) out(400, ['ok' => false, 'error' => "Неподдерживаемый формат '$key' ($mime)"]);

  $bin = file_get_contents($tmp);
  if ($bin === false) out(500, ['ok' => false, 'error' => "Не удалось прочитать '$key'"]);

  return ['mime' => $mime, 'data_url' => 'data:' . $mime . ';base64,' . base64_encode($bin)];
}

$full = require_image('full');
$face = null;
if (isset($_FILES['face']) && is_array($_FILES['face']) && ($_FILES['face']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
  $face = require_image('face');
}

$bulletsText = '';
if (is_array($bullets)) {
  $safe = array_slice(array_map(fn($x) => trim((string)$x), $bullets), 0, 8);
  $safe = array_values(array_filter($safe, fn($x) => $x !== ''));
  if ($safe) $bulletsText = 'Ключевые черты: ' . implode('; ', $safe) . '.';
}

/**
 * КЛЮЧЕВОЕ ИЗМЕНЕНИЕ:
 * - НЕ просим "3 варианта" в одном изображении
 * - делаем 3 отдельных запроса, каждый отдаёт 1 картинку (n=1)
 * - жёстко запрещаем коллажи/триптихи/несколько людей
 */
$basePrompt = "Сгенерируй ФОТОРЕАЛИСТИЧНЫЙ образ одежды НА ЭТОМ ЖЕ человеке (полный рост) для мероприятия: {$event}.
Учитывай типаж: {$type}. Обоснование: {$reason}. {$bulletsText}
Сохрани лицо, прическу и пропорции человека максимально точно. Реалистичные ткани и посадка.
В кадре: ОДИН человек, ОДНА композиция, ОДИН образ.
НЕ делай коллаж, триптих, сетку, split-screen, несколько персонажей, несколько вариантов в одном изображении.
Требования к безопасности/скромности:
- ОДИН человек, ОДНА сцена, ОДИН образ (не коллаж/не сетка).
- Образ НЕ сексуализированный: без белья, купальников, прозрачных тканей, откровенных вырезов, ультра-коротких юбок/шорт.
- Никакой наготы/эротики.
- Реалистичные ткани, посадка, аккуратный стиль.
- Сохрани лицо, причёску и пропорции максимально точно.";

// Worker /outfits
$proxyOutfitsUrl = rtrim($proxyUrl, '/') . '/outfits';

// public save dir
$outDir = '/var/www/u3380884/data/www/aistylemate.ru/uploads/outfits';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) out(500, ['ok' => false, 'error' => 'Не удалось создать uploads/outfits']);
if (!is_writable($outDir)) out(500, ['ok' => false, 'error' => 'uploads/outfits не доступен на запись']);

$urls = [];

for ($i = 1; $i <= 3; $i++) {
  $prompt = $basePrompt . "\n\nВариант {$i} из 3: сделай отличающийся по цветовой гамме и силуэту. Всё ещё один образ на одном человеке.";

  $payload = [
    'prompt' => $prompt,
    'images' => array_values(array_filter([
      $full['data_url'],
      $face['data_url'] ?? null,
    ])),
    'n' => 1,                 // ✅ строго 1 картинка за запрос
    'size' => '1024x1536',
  ];

  $ch = curl_init($proxyOutfitsUrl);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 240,
  ]);

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) out(500, ['ok' => false, 'error' => 'Proxy curl error', 'debug' => $err]);

  $worker = json_decode($raw, true);

  function is_moderation_blocked($worker): bool {
    if (!is_array($worker)) return false;

    // Worker иногда возвращает: { ok:false, error:"OpenAI images error", debug:{ error:{ code:"moderation_blocked", ... } } }
    $debug = $worker['debug'] ?? null;
    if (is_array($debug) && isset($debug['error']) && is_array($debug['error'])) {
        $err = $debug['error'];
        if (($err['code'] ?? null) === 'moderation_blocked') return true;

        $msg = (string)($err['message'] ?? '');
        if (stripos($msg, 'safety system') !== false) return true;
        if (stripos($msg, 'safety_violations') !== false) return true;
    }

    // Иногда код может всплыть на верхнем уровне
    $msg2 = (string)($worker['error'] ?? '');
    if (stripos($msg2, 'moderation') !== false) return true;

    return false;
    }
  if ($code < 200 || $code >= 300) {
  if (is_moderation_blocked($worker)) {
        out(422, [
        'ok' => false,
        'code' => 'moderation_blocked',
        'error' => 'Фото не подходит для генерации (слишком откровенная одежда или качество). Пожалуйста, загрузите фото в обычной одежде.',
        ]);
    }
    out(502, ['ok' => false, 'error' => 'Proxy/OpenAI error', 'debug' => $worker ?: mb_substr($raw, 0, 500)]);
    }

    if (!is_array($worker) || !($worker['ok'] ?? false)) {
    if (is_moderation_blocked($worker)) {
        out(422, [
        'ok' => false,
        'code' => 'moderation_blocked',
        'error' => 'Фото не подходит для генерации (слишком откровенная одежда или качество). Пожалуйста, загрузите фото в обычной одежде.',
        ]);
    }
    out(502, ['ok' => false, 'error' => 'Worker format error', 'debug' => $worker ?: mb_substr($raw, 0, 500)]);
    }
  $imagesB64 = $worker['images_b64'] ?? null;
  if (!is_array($imagesB64) || !isset($imagesB64[0])) out(502, ['ok' => false, 'error' => 'Нет изображения от Worker', 'debug' => $worker]);

  $bin = base64_decode((string)$imagesB64[0], true);
  if ($bin === false) out(502, ['ok' => false, 'error' => 'Base64 decode failed']);

  $name = 'look_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $i . '.png';
  $path = $outDir . '/' . $name;

  if (file_put_contents($path, $bin) === false) out(502, ['ok' => false, 'error' => 'Не удалось сохранить изображение']);
  $urls[] = '/uploads/outfits/' . $name;
}

out(200, ['ok' => true, 'images' => $urls]);
