<?php
// FILE: /www/api/proxy-openai.php
// Прокси для OpenAI API (работает из России)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Разрешаем CORS предзапросы
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Только POST запросы']);
    exit;
}

// 1. Загружаем конфиг OpenAI
$wwwRoot = dirname(__DIR__, 1); // /www
$configPath = $wwwRoot . '/_private/openai.php';

if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Конфиг OpenAI не найден']);
    exit;
}

$config = require $configPath;
$apiKey = trim($config['OPENAI_API_KEY'] ?? '');

if (!$apiKey || $apiKey === 'PASTE_YOUR_OPENAI_KEY_HERE') {
    http_response_code(500);
    echo json_encode(['error' => 'OPENAI_API_KEY не настроен']);
    exit;
}

// 2. Получаем данные запроса
$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Пустой запрос']);
    exit;
}

// 3. Парсим JSON
$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный JSON: ' . json_last_error_msg()]);
    exit;
}

// 4. Обязательные параметры
if (empty($data['model']) || empty($data['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Отсутствуют model или messages']);
    exit;
}

// 5. Отправляем запрос в OpenAI
$url = 'https://api.openai.com/v1/chat/completions';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60, // Увеличиваем таймаут для анализа
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
    
    // Дополнительные настройки для стабильности
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// 6. Обрабатываем ответ
if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Ошибка подключения к OpenAI',
        'debug' => $error
    ]);
    exit;
}

// 7. Возвращаем ответ как есть
http_response_code($httpCode);
echo $response;