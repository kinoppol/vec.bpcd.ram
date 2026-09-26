<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

// แสดงรูปห้องตัวอย่างของประเภทห้อง (เก็บไว้นอกเว็บใน storage/room_types — ต้องเข้าสู่ระบบก่อน)
Auth::require();
$st = Db::pdo()->prepare('SELECT image FROM room_types WHERE id=?');
$st->execute([(int)($_GET['t'] ?? 0)]);
$path = room_type_image_path((string)$st->fetchColumn());
if ($path === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (ROOM_IMAGE_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
