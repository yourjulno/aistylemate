<?php
// ВКЛЮЧАЕМ ОТЛАДКУ
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Логируем в файл для отладки
$logFile = __DIR__ . '/subscribe_log.txt';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Начало\n", FILE_APPEND);

// Проверяем метод
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents($logFile, "Ошибка: не POST метод\n", FILE_APPEND);
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// Получаем данные
$input = json_decode(file_get_contents('php://input'), true);
$rawInput = file_get_contents('php://input');
file_put_contents($logFile, "Raw input: " . $rawInput . "\n", FILE_APPEND);

$email = isset($input['email']) ? trim($input['email']) : '';
file_put_contents($logFile, "Email получен: " . $email . "\n", FILE_APPEND);

// Валидация
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    file_put_contents($logFile, "Ошибка валидации email\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный email']);
    exit;
}

// ДАННЫЕ БД - ЗАМЕНИТЕ НА ВАШИ!
$host = 'localhost';
$dbname = 'stylemate_db'; // ваше имя БД
$username = 'stylemate_user'; // ваш пользователь
$password = '938520eeEERR'; // ваш пароль

file_put_contents($logFile, "Пытаемся подключиться к БД: $dbname, пользователь: $username\n", FILE_APPEND);

try {
    // Подключаемся к БД
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    file_put_contents($logFile, "✅ Подключение к БД успешно\n", FILE_APPEND);
    
    // Дополнительные данные
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Проверяем таблицу
    $stmt = $pdo->query("SELECT COUNT(*) FROM waitlist_emails");
    file_put_contents($logFile, "✅ Таблица доступна\n", FILE_APPEND);
    
    // Вставляем email
    $stmt = $pdo->prepare("
        INSERT INTO waitlist_emails (email, ip_address, user_agent) 
        VALUES (:email, :ip, :ua)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
    ");
    
    $stmt->execute([
        ':email' => strtolower($email),
        ':ip' => $ip,
        ':ua' => $userAgent
    ]);
    
    $rowCount = $stmt->rowCount();
    file_put_contents($logFile, "✅ Email сохранен. Затронуто строк: $rowCount\n", FILE_APPEND);
    
    // Успех
    echo json_encode([
        'ok' => true, 
        'message' => 'Email добавлен в список ожидания'
    ]);
    
} catch (PDOException $e) {
    // Логируем ошибку
    $errorMsg = "❌ Ошибка БД: " . $e->getMessage();
    file_put_contents($logFile, $errorMsg . "\n", FILE_APPEND);
    
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'error' => 'Ошибка сервера. Попробуйте позже.',
        'debug' => $e->getMessage() // временно для отладки
    ]);
}

file_put_contents($logFile, date('Y-m-d H:i:s') . " - Конец\n\n", FILE_APPEND);
?>