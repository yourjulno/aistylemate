<?php
// FILE: /api/outfits_sse.php
declare(strict_types=1);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // важно для nginx
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(true);

function sse(string $event, array $data): void {
  echo "event: {$event}\n";
  echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
  @flush();
}

function stop_with_error(int $httpCode, string $msg): void {
  http_response_code($httpCode);
  sse('error', ['ok' => false, 'error' => $msg]);
  exit;
}

$job = preg_replace('/[^a-f0-9]/', '', (string)($_GET['job'] ?? ''));
if ($job === '' || strlen($job) < 16) stop_with_error(400, 'Некорректный job');

$jobDir = __DIR__ . '/../uploads/_jobs/' . $job;
$metaPath = $jobDir . '/meta.json';
if (!is_file($metaPath)) stop_with_error(404, 'Job не найден');

$meta = json_decode((string)file_get_contents($metaPath), true);
if (!is_array($meta)) stop_with_error(500, 'meta.json повреждён');

$openaiConfigPath = __DIR__ . '/../../_private/openai.php';
if (!is_file($openaiConfigPath)) stop_with_error(500, 'Конфиг OpenAI не найден');
$openaiCfg = require $openaiConfigPath;

$apiKey = trim((string)($openaiCfg['OPENAI_API_KEY'] ?? ''));
$proxyUrl = trim((string)($openaiCfg['OPENAI_PROXY_URL'] ?? ''));
if ($apiKey === '' || $proxyUrl === '') stop_with_error(500, 'OPENAI_PROXY_URL/KEY не заданы');

$proxyOutfitsUrl = rtrim($proxyUrl, '/') . '/outfits';

$eventText = (string)($meta['event'] ?? '');
$archetype = $meta['archetype'] ?? null;
$fullPath = (string)($meta['full_path'] ?? '');
$facePath = (string)($meta['face_path'] ?? '');

if (!is_array($archetype) || $eventText === '' || !is_file($fullPath)) stop_with_error(500, 'Job метаданные невалидны');

function file_to_dataurl(string $path): string {
  $mime = mime_content_type($path) ?: 'image/jpeg';
  $bin = file_get_contents($path);
  if ($bin === false) throw new RuntimeException('Не удалось прочитать файл');
  return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

$type = trim((string)($archetype['type'] ?? ''));
$reason = trim((string)($archetype['reason'] ?? ''));
$bullets = is_array($archetype['bullets'] ?? null) ? $archetype['bullets'] : [];

$bulletsText = '';
if (is_array($bullets)) {
  $safe = array_slice(array_map(fn($x) => trim((string)$x), $bullets), 0, 8);
  $safe = array_values(array_filter($safe, fn($x) => $x !== ''));
  if ($safe) $bulletsText = 'Ключевые черты: ' . implode('; ', $safe) . '.';
}

// ⬇️ Промпт: строго 1 образ на 1 картинку (без коллажей) + не сексуализировано
$basePrompt = "Сгенерируй ФОТОРЕАЛИСТИЧНЫЙ образ одежды НА ЭТОМ ЖЕ человеке (полный рост) для мероприятия: {$event}.
Учитывай типаж: {$type}. Обоснование: {$reason}. {$bulletsText}
Сохрани лицо, прическу и пропорции человека максимально точно. Реалистичные ткани и посадка.
В кадре: ОДИН человек, ОДНА композиция, ОДИН образ.
НЕ делай коллаж, триптих, сетку, split-screen, несколько персонажей, несколько вариантов в одном изображении.
Сделай образ максимально приближенным к жизни, не делай нереалистичный фон.
Требования к безопасности/скромности:
- ОДИН человек, ОДНА сцена, ОДИН образ (не коллаж/не сетка).
- Образ НЕ сексуализированный: без белья, купальников, прозрачных тканей.
- Никакой наготы/эротики.
- Реалистичные ткани, посадка, аккуратный стиль.
- Сохрани лицо, причёску и пропорции максимально точно.";

$outDir = __DIR__ . '/../uploads/outfits';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) stop_with_error(500, 'Не удалось создать uploads/outfits');
if (!is_writable($outDir)) stop_with_error(500, 'uploads/outfits не доступен на запись');

sse('start', ['ok' => true, 'job' => $job, 'total' => 1]);

$imagesUrls = [];

try {
  $fullDataUrl = file_to_dataurl($fullPath);
  $faceDataUrl = (is_file($facePath) ? file_to_dataurl($facePath) : null);

  for ($i = 1; $i <= 1; $i++) {
    sse('progress', ['step' => $i, 'status' => 'generating']);

    $prompt = $basePrompt . "\n\nВариант {$i} из 2: отличайся по цветовой гамме и силуэту, но соблюдай ограничения.";

    $payload = [
      'prompt' => $prompt,
      'images' => array_values(array_filter([$fullDataUrl, $faceDataUrl])),
      'n' => 1,
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

    if ($raw === false) stop_with_error(502, 'Proxy curl error: ' . $err);

    $worker = json_decode($raw, true);

    // Safety message for user
    $dbg = is_array($worker) ? ($worker['debug'] ?? null) : null;
    $dbgErr = (is_array($dbg) && is_array($dbg['error'] ?? null)) ? $dbg['error'] : null;
    $codeStr = is_array($dbgErr) ? (string)($dbgErr['code'] ?? '') : '';
    $msgStr = is_array($dbgErr) ? (string)($dbgErr['message'] ?? '') : '';

    $isModeration =
      $codeStr === 'moderation_blocked' ||
      stripos($msgStr, 'safety system') !== false ||
      stripos($msgStr, 'safety_violations') !== false;

    if ($code < 200 || $code >= 300 || !is_array($worker) || !($worker['ok'] ?? false)) {
      if ($isModeration) {
        stop_with_error(422, 'Фото не подходит для генерации (слишком откровенная одежда или качество). Пожалуйста, загрузите фото в обычной одежде.');
      }
      stop_with_error(502, 'Proxy/OpenAI error');
    }

    $b64arr = $worker['images_b64'] ?? null;
    if (!is_array($b64arr) || !isset($b64arr[0])) stop_with_error(502, 'Нет изображения от Worker');

    $bin = base64_decode((string)$b64arr[0], true);
    if ($bin === false) stop_with_error(502, 'Base64 decode failed');

    $name = 'look_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $i . '.png';
    $path = $outDir . '/' . $name;
    if (file_put_contents($path, $bin) === false) stop_with_error(500, 'Не удалось сохранить изображение');

    $url = '/uploads/outfits/' . $name;
    $imagesUrls[] = $url;

    sse('progress', ['step' => $i, 'status' => 'done', 'url' => $url]);
  }

  sse('done', ['ok' => true, 'images' => $imagesUrls]);

} catch (Throwable $e) {
  stop_with_error(500, 'Internal error: ' . $e->getMessage());
} finally {
  // cleanup job files
  @unlink($metaPath);
  foreach (glob($jobDir . '/*') as $f) @unlink($f);
  @rmdir($jobDir);
}
