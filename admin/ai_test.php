<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// ทดสอบการเชื่อมต่อ AI ด้วยค่าจากฟอร์ม "ก่อนบันทึก" — ไม่เขียนอะไรลงฐานข้อมูล
header('Content-Type: application/json; charset=utf-8');
function jout(array $d, int $code = 200): never
{
    http_response_code($code);
    exit(json_encode($d, JSON_UNESCAPED_UNICODE));
}

$u = Auth::user();
if (!$u || $u['role'] !== 'admin') {
    jout(['ok' => false, 'error' => 'ไม่มีสิทธิ์'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    jout(['ok' => false, 'error' => 'CSRF ไม่ถูกต้อง'], 419);
}
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$cfg = ai_cfg([
    'profile' => (int)($in['profile'] ?? 0) ?: -1, // แก้ไขรายการเดิม: เว้นคีย์ว่าง = ใช้คีย์ของรายการนั้น / รายการใหม่: ไม่ใช้คีย์ของรายการอื่น
    'provider' => (string)($in['provider'] ?? ''),
    'base' => trim((string)($in['base'] ?? '')),
    'key' => trim((string)($in['key'] ?? '')),
    'model' => trim((string)($in['model'] ?? '')),
]);

if (($in['action'] ?? '') === 'models') {
    jout(ai_models($cfg));
}
if ($cfg['model'] === '') {
    jout(['ok' => false, 'error' => 'กรุณาเลือกหรือกรอกชื่อโมเดล']);
}
$r = ai_chat([['role' => 'user', 'content' => 'สวัสดี']], $cfg);
jout(['ok' => $r['ok'], 'error' => $r['ok'] ? '' : $r['text'], 'reply' => $r['ok'] ? mb_substr($r['text'], 0, 300) : '']);
