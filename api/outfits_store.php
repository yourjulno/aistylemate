<?php
// FILE: /var/www/u3380884/data/www/aistylemate.ru/api/outfits_store.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  out(405, ['ok' => false, 'error' => 'Method Not Allowed']);
}

$cfgPath = '/var/www/u3380884/data/www/_private/openai.php';
if (!is_file($cfgPath)) out(500, ['ok' => false, 'error' => 'Config not found']);

$cfg = require $cfgPath;
$secret = trim((string)($cfg['WORKER_UPLOAD_SECRET'] ?? ''));
if ($secret === '') out(500, ['ok' => false, 'error' => 'WORKER_UPLOAD_SECRET not set']);

$got = trim((string)($_SERVER['HTTP_X_WORKER_SECRET'] ?? ''));
if (!hash_equals($secret, $got)) out(403, ['ok' => false, 'error' => 'Forbidden']);

$job = trim((string)($_POST['job'] ?? ''));
$slot = trim((string)($_POST['slot'] ?? '')); // "input" | "out_1"
if (!preg_match('/^[a-f0-9]{24}$/', $job)) out(400, ['ok' => false, 'error' => 'Bad job']);
if (!in_array($slot, ['input', 'mask', 'face', 'out_1', 'out_2'], true)) out(400, ['ok' => false, 'error' => 'Bad slot']);

if ($slot === 'input') $filename = 'input.png';
elseif ($slot === 'mask') $filename = 'mask.png';
elseif ($slot === 'face') $filename = 'face.png';
elseif ($slot === 'out_1') $filename = 'out_1.png';
else $filename = 'out_2.png';

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  out(400, ['ok' => false, 'error' => 'No file']);
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  out(400, ['ok' => false, 'error' => 'Upload error']);
}

$max = 6 * 1024 * 1024;
$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > $max) out(400, ['ok' => false, 'error' => 'File too big']);

$tmp = (string)($f['tmp_name'] ?? '');
$mime = mime_content_type($tmp) ?: '';
if ($mime !== 'image/png') out(400, ['ok' => false, 'error' => 'Only PNG allowed', 'got' => $mime]);

$root = '/var/www/u3380884/data/www/aistylemate.ru';
$relDir = '/uploads/jobs/' . $job;
$absDir = $root . $relDir;

if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
  out(500, ['ok' => false, 'error' => 'Cannot create dir']);
}

$filename = $slot === 'input' ? 'input.png' : 'out_1.png';
$absPath = $absDir . '/' . $filename;

if (!move_uploaded_file($tmp, $absPath)) {
  out(500, ['ok' => false, 'error' => 'Cannot save file']);
}

@chmod($absPath, 0644);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'aistylemate.ru';
$url = $scheme . '://' . $host . $relDir . '/' . $filename;

out(200, ['ok' => true, 'job' => $job, 'slot' => $slot, 'url' => $url]);
