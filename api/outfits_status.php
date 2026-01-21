<?php
// FILE: /var/www/u3380884/data/www/aistylemate.ru/api/outfits_status.php
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

$dbConfigPath = '/var/www/u3380884/data/www/_private/db.php';
if (!is_file($dbConfigPath)) out(500, ['ok' => false, 'error' => 'Конфиг БД не найден']);
$dbCfg = require $dbConfigPath;

$job = trim((string)($_GET['job'] ?? ''));
if (!preg_match('/^[a-f0-9]{24}$/', $job)) out(400, ['ok' => false, 'error' => 'Bad job']);

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

  $stmt = $pdo->prepare("SELECT status, error_text, images_json FROM outfit_jobs WHERE job=:job LIMIT 1");
  $stmt->execute([':job' => $job]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) out(404, ['ok' => false, 'error' => 'Not found']);

  $status = (string)$row['status'];
  $images = [];
  if (!empty($row['images_json'])) {
    $j = json_decode((string)$row['images_json'], true);
    if (is_array($j)) $images = $j;
  }

  out(200, [
    'ok' => true,
    'status' => $status,
    'error' => $status === 'error' ? (string)($row['error_text'] ?? '') : null,
    'images' => $status === 'done' ? $images : [],
  ]);
} catch (Throwable $e) {
  out(500, ['ok' => false, 'error' => 'DB error (outfits_status)']);
}
