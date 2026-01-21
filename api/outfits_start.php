<?php
// FILE: /var/www/u3380884/data/www/aistylemate.ru/api/outfits_start.php
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
if (!is_file($dbConfigPath)) out(500, ['ok' => false, 'error' => 'Конфиг БД не найден']);

$dbCfg = require $dbConfigPath;

function is_valid_email(string $s): bool {
  return (bool)filter_var($s, FILTER_VALIDATE_EMAIL);
}

function safe_job_id(): string {
  return bin2hex(random_bytes(12)); // 24 hex chars
}

function require_png_upload(string $key, int $maxBytes): array {
  if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) out(400, ['ok' => false, 'error' => "Файл '$key' не найден"]);
  $f = $_FILES[$key];
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) out(400, ['ok' => false, 'error' => "Ошибка загрузки '$key'"]);

  $size = (int)($f['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) out(400, ['ok' => false, 'error' => "PNG слишком большой (макс " . (int)($maxBytes/1024/1024) . "MB)"]);

  $tmp = (string)($f['tmp_name'] ?? '');
  $mime = mime_content_type($tmp) ?: '';
  if ($mime !== 'image/png') out(400, ['ok' => false, 'error' => "Нужно PNG. Получено: $mime"]);

  $bin = file_get_contents($tmp);
  if ($bin === false) out(500, ['ok' => false, 'error' => "Не удалось прочитать '$key'"]);

  // PNG signature check
  if (strlen($bin) < 8 || substr($bin, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") {
    out(400, ['ok' => false, 'error' => 'Файл не похож на PNG (сигнатура)']);
  }

  return ['bin' => $bin];
}

$email = trim((string)($_POST['email'] ?? ''));
$event = trim((string)($_POST['event'] ?? ''));
$archetypeRaw = trim((string)($_POST['archetype'] ?? ''));

if (!is_valid_email($email)) out(400, ['ok' => false, 'error' => 'Некорректный email']);
if ($event === '' || mb_strlen($event) > 80) out(400, ['ok' => false, 'error' => 'Некорректное мероприятие']);

$archetype = json_decode($archetypeRaw, true);
if (!is_array($archetype) || empty($archetype['type']) || empty($archetype['reason'])) {
  out(400, ['ok' => false, 'error' => 'Некорректный archetype']);
}

$png = require_png_upload('full', 4 * 1024 * 1024);

$job = safe_job_id();
$relDir = "/uploads/jobs/$job";
$absDir = $_SERVER['DOCUMENT_ROOT'] . $relDir;

if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
  out(500, ['ok' => false, 'error' => 'Не удалось создать папку job']);
}

$inputRel = "$relDir/input.png";
$inputAbs = $_SERVER['DOCUMENT_ROOT'] . $inputRel;

if (file_put_contents($inputAbs, $png['bin']) === false) {
  out(500, ['ok' => false, 'error' => 'Не удалось сохранить input.png']);
}

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

  $stmt = $pdo->prepare("
    INSERT INTO outfit_jobs (job, email, event, archetype_json, input_rel, status)
    VALUES (:job, :email, :event, :arch, :input_rel, 'queued')
  ");
  $stmt->execute([
    ':job' => $job,
    ':email' => strtolower($email),
    ':event' => $event,
    ':arch' => json_encode($archetype, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ':input_rel' => $inputRel,
  ]);
} catch (Throwable $e) {
  out(500, ['ok' => false, 'error' => 'DB error (outfits_start)']);
}

out(200, ['ok' => true, 'job' => $job]);
