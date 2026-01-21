<?php
// FILE: /var/www/u3380884/data/www/aistylemate.ru/api/regru_fetch.php
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');

// Для мобильных устройств добавляем специальные заголовки
if (strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Mobile') !== false) {
    header('X-Mobile-Optimized: 1');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

$url = $_GET['url'] ?? '';
$mobile = isset($_GET['mobile']) ? (int)$_GET['mobile'] : 0;

if (empty($url)) {
    http_response_code(400);
    exit;
}

// Разрешаем только свои домены
$allowedDomains = [
    'u3380884.cp.regruhosting.ru',
    'aistylemate.ru',
    'localhost'
];

$parsed = parse_url($url);
if (!$parsed || !isset($parsed['host'])) {
    http_response_code(400);
    exit;
}

$isAllowed = false;
foreach ($allowedDomains as $domain) {
    if (strpos($parsed['host'], $domain) !== false) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    exit;
}

// Увеличиваем лимиты для больших изображений
ini_set('memory_limit', '256M');
set_time_limit(60);

// Загружаем изображение через cURL
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0 Mobile',
]);

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || !$imageData) {
    // Для мобильных отдаем заглушку меньшего размера
    $placeholder = imagecreatetruecolor($mobile ? 300 : 400, $mobile ? 450 : 600);
    $bgColor = imagecolorallocate($placeholder, 240, 240, 240);
    $textColor = imagecolorallocate($placeholder, 150, 150, 150);
    imagefill($placeholder, 0, 0, $bgColor);
    imagestring($placeholder, 3, 30, 200, 'Image temporarily unavailable', $textColor);
    
    header('Content-Type: image/jpeg');
    imagejpeg($placeholder, null, 80);
    imagedestroy($placeholder);
    exit;
}

// Для мобильных сжимаем изображение
if ($mobile && (strpos($contentType, 'image/png') !== false || strpos($contentType, 'image/jpeg') !== false)) {
    $sourceImage = imagecreatefromstring($imageData);
    if ($sourceImage !== false) {
        $originalWidth = imagesx($sourceImage);
        $originalHeight = imagesy($sourceImage);
        
        // Сжимаем до разумных размеров для мобильных
        $maxWidth = 1200;
        $maxHeight = 1600;
        
        if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
            $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
            $newWidth = (int)($originalWidth * $ratio);
            $newHeight = (int)($originalHeight * $ratio);
            
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Сохраняем прозрачность для PNG
            if (strpos($contentType, 'image/png') !== false) {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, 
                $newWidth, $newHeight, $originalWidth, $originalHeight);
            
            imagedestroy($sourceImage);
            
            // Выводим сжатое изображение как JPEG для экономии
            header('Content-Type: image/jpeg');
            ob_start();
            imagejpeg($resizedImage, null, 85);
            $imageData = ob_get_clean();
            imagedestroy($resizedImage);
        } else {
            imagedestroy($sourceImage);
            // Отдаем как JPEG даже если это был PNG
            header('Content-Type: image/jpeg');
            ob_start();
            $sourceImage = imagecreatefromstring($imageData);
            imagejpeg($sourceImage, null, 90);
            $imageData = ob_get_clean();
            imagedestroy($sourceImage);
        }
    }
} else {
    // Для десктопа оставляем оригинальный Content-Type
    if ($contentType && strpos($contentType, 'image/') === 0) {
        header("Content-Type: $contentType");
    }
}

echo $imageData;
exit;