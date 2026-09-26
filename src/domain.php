<?php
declare(strict_types=1);

const PROJECT_STATUS = [
    'pending' => ['รอตรวจสอบ', 'pend'],
    'checking' => ['กำลังตรวจสอบ', 'pend'],
    'approved' => ['อนุมัติแล้ว', 'ok'],
    'rejected' => ['ไม่อนุมัติ', 'bad'],
    'cancelled' => ['ยกเลิก', 'bad'],
];
const ROOM_STATUS = [
    'available' => ['ว่าง', '#2F6B4F'],
    'reserved' => ['จองแล้ว', '#8A6100'],
    'occupied' => ['มีผู้พัก', '#7A1E2C'],
    'cleaning' => ['ทำความสะอาด', '#3B6FA0'],
    'maintenance' => ['ปิดซ่อม', '#8A8F98'],
    'unavailable' => ['ไม่ว่าง', '#6B4E9B'],
];
const GUEST_TYPE = ['trainee' => 'ผู้เข้าอบรม', 'speaker' => 'วิทยากร', 'committee' => 'กรรมการ', 'exec' => 'ผู้บริหาร', 'other' => 'อื่น ๆ'];
const GUEST_STATUS = [
    'expected' => ['ยังไม่มา', 'pend'], 'checked_in' => ['เข้าพักแล้ว', 'ok'],
    'checked_out' => ['Check-out แล้ว', 'ok'], 'no_show' => ['No-show', 'bad'],
];

/** สถานะที่ควรระบุสาเหตุ (ไม่ว่าง = ใช้ในภารกิจอื่น ต้องมีเหตุผล / ปิดซ่อม ระบุหรือไม่ก็ได้) */
const ROOM_STATUS_WITH_NOTE = ['unavailable', 'maintenance'];

function badge(array $map, string $key): string
{
    [$label, $cls] = $map[$key] ?? [$key, 'pend'];
    return '<span class="badge ' . e($cls) . '">' . e($label) . '</span>';
}

function audit(string $action): void
{
    $u = Auth::user();
    Db::pdo()->prepare('INSERT INTO audit_log (user_id,action) VALUES (?,?)')->execute([$u['id'] ?? null, $action]);
}

function thai_date(?string $d): string
{
    if (!$d) {
        return '';
    }
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $t = strtotime($d);
    return date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . ((int)date('Y', $t) + 543);
}

/**
 * ช่วงวันเข้าพักเริ่มต้นของผู้เข้าพัก คำนวณจากวันโครงการ: เข้าพักล่วงหน้า 1 คืนก่อนวันแรก
 * และไม่จัดคืนของวันสุดท้าย (ผู้เกี่ยวข้องมักเดินทางกลับวันสุดท้ายไม่ค้างคืน)
 * @return array{0:string,1:string} [stay_start, stay_end] (stay_end = วันออก ไม่รวมคืนนั้น)
 */
function guest_default_stay(array $project): array
{
    $start = (new DateTimeImmutable($project['start_date']))->modify('-1 day')->format('Y-m-d');
    $end = $project['end_date'];
    return [$start, $end];
}

/**
 * สถานะห้องทุกห้อง ณ วันที่กำหนด (สำหรับดูย้อนหลัง/ล่วงหน้า): วันนี้ใช้สถานะจริงปัจจุบันตรง ๆ
 * วันอื่นคำนวณจากการจอง (ผู้เข้าพักคลุมวันนั้น = ห้องพักไม่ว่าง, โครงการจองคลุมวันนั้น = ห้องประชุมไม่ว่าง)
 * สถานะปิดใช้งานเชิงบริหาร (maintenance/unavailable) คงไว้ทุกวันที่ เพราะไม่มีข้อมูลกำหนดวันสิ้นสุด
 * @return array<int,string> [room_id => status]
 */
function room_statuses_on_date(PDO $db, string $date): array
{
    $statuses = $types = [];
    foreach ($db->query('SELECT id,status,type FROM rooms')->fetchAll() as $r) {
        $statuses[(int)$r['id']] = $r['status'];
        $types[(int)$r['id']] = $r['type'];
    }
    if ($date === date('Y-m-d')) {
        return $statuses;
    }
    $st = $db->prepare("SELECT room_id FROM guests WHERE room_id IS NOT NULL AND status IN ('expected','checked_in')
        AND (stay_start IS NULL OR stay_start<=?) AND (stay_end IS NULL OR stay_end>?) GROUP BY room_id");
    $st->execute([$date, $date]);
    $occRooms = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $mt = $db->prepare("SELECT meeting_room_id FROM projects WHERE meeting_room_id IS NOT NULL AND status IN ('pending','checking','approved') AND start_date<=? AND end_date>=?");
    $mt->execute([$date, $date]);
    $bookedMeeting = array_flip(array_map('intval', $mt->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($statuses as $rid => $s) {
        if (in_array($s, ['maintenance', 'unavailable'], true)) {
            continue;
        }
        $statuses[$rid] = $types[$rid] === 'meeting'
            ? (isset($bookedMeeting[$rid]) ? 'reserved' : 'available')
            : (isset($occRooms[$rid]) ? 'reserved' : 'available');
    }
    return $statuses;
}

/** โครงการอื่นที่ใช้ห้องประชุมเดียวกันและวันชนกัน */
function project_conflicts(array $p): array
{
    if (empty($p['meeting_room_id'])) {
        return [];
    }
    $st = Db::pdo()->prepare("SELECT id,name FROM projects WHERE id<>? AND meeting_room_id=? AND status IN ('pending','checking','approved')
        AND start_date<=? AND end_date>=?");
    $st->execute([$p['id'] ?? 0, $p['meeting_room_id'], $p['end_date'], $p['start_date']]);
    return $st->fetchAll();
}

function post_action(): ?string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return null;
    }
    csrf_check();
    return (string)($_POST['action'] ?? '');
}

// ---------- ตั้งค่าระบบ / แจ้งเตือน / AI ----------
function setting(string $k, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = Db::pdo()->query('SELECT k,v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable) {
            $cache = []; // ยังไม่ได้รัน migration
        }
    }
    return (string)($cache[$k] ?? $default);
}

function save_setting(string $k, string $v): void
{
    Db::pdo()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]);
}

/** @return array{0:int,1:string} [http status, body] */
function http_json(string $url, array $headers, ?array $payload): array
{
    if (!function_exists('curl_init')) {
        return [0, 'ไม่พบ PHP extension curl'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($payload !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $body === false ? $err : (string)$body];
}

function log_notify(string $channel, string $to, string $subject, string $status, ?string $err = null): void
{
    Db::pdo()->prepare('INSERT INTO notifications (channel,recipient,subject,status,error) VALUES (?,?,?,?,?)')
        ->execute([$channel, $to, mb_substr($subject, 0, 255), $status, $err ? mb_substr($err, 0, 255) : null]);
}

/** ส่งอีเมลถึงรายการที่อยู่ + ส่งเข้า LINE (ถ้าตั้งค่าไว้) — ความล้มเหลวไม่กระทบการทำงานหลัก */
function notify(string $subject, string $body, array $emails = [], bool $line = false): void
{
    try {
        $from = setting('mail_from');
        foreach (array_unique(array_filter($emails)) as $to) {
            if ($from === '' || setting('mail_enabled') !== '1') {
                log_notify('email', $to, $subject, 'skipped', 'ยังไม่ได้เปิดใช้อีเมล');
                continue;
            }
            $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body,
                "From: $from\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0");
            log_notify('email', $to, $subject, $ok ? 'sent' : 'failed', $ok ? null : 'mail() ล้มเหลว');
        }
        if ($line) {
            $tok = setting('line_token');
            $target = setting('line_target');
            if ($tok === '' || $target === '') {
                log_notify('line', $target ?: '-', $subject, 'skipped', 'ยังไม่ได้ตั้งค่า LINE');
            } else {
                [$code, $res] = http_json('https://api.line.me/v2/bot/message/push', ["Authorization: Bearer $tok"],
                    ['to' => $target, 'messages' => [['type' => 'text', 'text' => mb_substr("$subject\n$body", 0, 4900)]]]);
                log_notify('line', $target, $subject, $code === 200 ? 'sent' : 'failed', $code === 200 ? null : "HTTP $code $res");
            }
        }
    } catch (Throwable) {
    }
}

function caretaker_emails(): array
{
    return Db::pdo()->query("SELECT email FROM users WHERE role IN ('caretaker','admin') AND active=1 AND email IS NOT NULL AND email<>''")->fetchAll(PDO::FETCH_COLUMN);
}

// ---------- ผู้ให้บริการ LLM (ทุกรายใช้รูปแบบ OpenAI-compatible /chat/completions) ----------
const AI_PROVIDERS = [
    'openai' => ['OpenAI', 'https://api.openai.com/v1', 'gpt-4o-mini'],
    'openrouter' => ['OpenRouter', 'https://openrouter.ai/api/v1', 'openai/gpt-4o-mini'],
    'google' => ['Google AI Studio (Gemini)', 'https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-2.0-flash'],
    'thaillm' => ['ThaiLLM', 'http://thaillm.or.th/api/v1', 'openthaigpt-thaillm-8b-instruct-v7.2'],
    'custom' => ['กำหนดเอง (OpenAI-compatible)', '', ''],
];

/** @return array{ok:bool,text:string} */
function ai_cfg(array $o = []): array
{
    $p = ai_profile((int)($o['profile'] ?? ai_default_profile_id())) ?? [];
    return [
        'provider' => (string)($o['provider'] ?? $p['provider'] ?? ''),
        'base' => rtrim((string)($o['base'] ?? $p['base_url'] ?? ''), '/'),
        'key' => (string)(($o['key'] ?? '') !== '' ? $o['key'] : ($p['api_key'] ?? '')), // เว้นว่าง = ใช้คีย์ที่บันทึกไว้ของโปรไฟล์
        'model' => (string)($o['model'] ?? $p['model'] ?? ''),
    ];
}

// ---------- โปรไฟล์ API หลายรายการ ----------
/** @return list<array> รายการ API ที่บันทึกไว้ (มี api_key — ห้ามส่งออกไปหน้าเว็บ) */
function ai_profiles(bool $enabledOnly = false): array
{
    try {
        return Db::pdo()->query('SELECT * FROM ai_profiles' . ($enabledOnly ? ' WHERE enabled=1' : '') . ' ORDER BY sort,id')->fetchAll();
    } catch (Throwable) {
        return []; // ยังไม่ได้รัน migration
    }
}

function ai_profile(int $id): ?array
{
    foreach (ai_profiles() as $p) {
        if ((int)$p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** โปรไฟล์เริ่มต้น: ค่าที่ admin เลือก ถ้าไม่มี/ปิดอยู่ใช้รายการแรกที่เปิดใช้งาน */
function ai_default_profile_id(): int
{
    $want = (int)setting('ai_default_profile');
    foreach (ai_profiles(true) as $p) {
        if ((int)$p['id'] === $want) {
            return $want;
        }
    }
    return (int)(ai_profiles(true)[0]['id'] ?? 0);
}

/**
 * เลือกโปรไฟล์สำหรับคำถามนี้
 * - โหมด user: ใช้ที่ผู้ใช้เลือก (ถ้าไม่ได้เลือก/ใช้ไม่ได้ ใช้ค่าเริ่มต้น)
 * - โหมด auto: คำถามสั้น (ไม่เกิน ai_simple_max ตัวอักษร) → ai_simple_profile, นอกนั้น → ai_default_profile
 * @return array{0:int,1:string} [id โปรไฟล์, เหตุผล]
 */
function ai_pick_profile(string $question, ?int $requested, bool $followUp = false): array
{
    $enabled = array_column(ai_profiles(true), null, 'id');
    $default = ai_default_profile_id();
    if (setting('ai_select_mode', 'user') === 'auto') {
        if ($followUp) { // ตอบต่อจากคำถามของผู้ช่วย: ต้องเข้าใจบริบท ไม่ใช้โมเดลสำหรับคำถามสั้น
            return [$default, 'ตอบต่อเนื่องจากบทสนทนา'];
        }
        $simple = (int)setting('ai_simple_profile');
        $max = max(1, (int)setting('ai_simple_max', '30'));
        if (mb_strlen($question) <= $max && isset($enabled[$simple])) {
            return [$simple, "คำถามสั้น (≤ $max ตัวอักษร)"];
        }
        return [$default, 'คำถามยาว/ซับซ้อน'];
    }
    if ($requested && isset($enabled[$requested])) {
        return [$requested, 'ผู้ใช้เลือก'];
    }
    return [$default, 'ค่าเริ่มต้น'];
}

/** ความสามารถของ API ที่ใช้ทำคำสั่งซับซ้อนหลายรายการ: ThaiLLM (โมเดลเล็ก) ไม่รองรับ */
function ai_supports_batch(array $cfg): bool
{
    return ($cfg['provider'] ?? '') !== 'thaillm' && stripos((string)($cfg['base'] ?? ''), 'thaillm') === false;
}

/** @param array|null $cfg ค่าจาก ai_cfg() — ไม่ส่ง = ใช้ค่าที่บันทึกไว้ (ใช้ทดสอบก่อนบันทึก) */
function ai_chat(array $messages, ?array $cfg = null, int $maxTokens = 2048): array
{
    $cfg ??= ai_cfg();
    $base = $cfg['base'];
    if (!preg_match('#^https?://#i', $base) || $cfg['key'] === '') {
        return ['ok' => false, 'text' => 'ยังไม่ได้ตั้งค่า Base URL หรือ API key'];
    }
    $payload = ['model' => $cfg['model'], 'messages' => $messages, 'temperature' => 0.3];
    if ($cfg['provider'] !== 'openai') {
        $payload['max_tokens'] = $maxTokens; // โมเดลใหม่ของ OpenAI ไม่รับ max_tokens จึงไม่ส่ง
    }
    [$code, $body] = http_json($base . '/chat/completions', ['Authorization: Bearer ' . $cfg['key']], $payload);
    $j = json_decode($body, true);
    $text = $j['choices'][0]['message']['content'] ?? null;
    if (is_string($text)) {
        // โมเดลแบบ reasoning บางตัว (เช่น ThaiLLM) ส่งความคิดภายในมาในแท็ก <think>
        $text = trim((string)preg_replace('#<think>.*?(</think>|$)#s', '', $text));
    }
    if ($code === 200 && is_string($text) && $text !== '') {
        return ['ok' => true, 'text' => $text];
    }
    $err = is_array($j) ? ($j['error']['message'] ?? $j['message'] ?? '') : '';
    return ['ok' => false, 'text' => "HTTP $code " . mb_substr((string)($err ?: $body), 0, 200)];
}

/** ดึงรายชื่อโมเดลจาก GET {base}/models (รูปแบบ OpenAI) @return array{ok:bool,models:list<string>,error:string} */
function ai_models(array $cfg): array
{
    if (!preg_match('#^https?://#i', $cfg['base']) || $cfg['key'] === '') {
        return ['ok' => false, 'models' => [], 'error' => 'กรุณากรอก Base URL และ API key'];
    }
    [$code, $body] = http_json($cfg['base'] . '/models', ['Authorization: Bearer ' . $cfg['key']], null);
    $j = json_decode($body, true);
    if ($code !== 200 || !is_array($j)) {
        $err = is_array($j) ? ($j['error']['message'] ?? $j['message'] ?? '') : '';
        return ['ok' => false, 'models' => [], 'error' => "HTTP $code " . mb_substr((string)($err ?: $body), 0, 200)];
    }
    $list = $j['data'] ?? $j['models'] ?? [];
    $ids = [];
    foreach ($list as $m) {
        $id = is_array($m) ? ($m['id'] ?? $m['name'] ?? '') : (string)$m;
        if ($id !== '') {
            $ids[] = preg_replace('#^models/#', '', (string)$id);
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return ['ok' => true, 'models' => $ids, 'error' => ''];
}

// ---------- ห้องย่อย (ห้องนอนภายในห้องเดียวกัน) ----------
/** ห้องที่มีห้องย่อย: ความจุเตียงของห้อง = ผลรวมเตียงของห้องย่อย */
function room_sync_beds(PDO $db, int $roomId): void
{
    $db->prepare('UPDATE rooms SET beds=(SELECT SUM(beds) FROM room_units WHERE room_id=?) WHERE id=? AND EXISTS(SELECT 1 FROM room_units WHERE room_id=?)')
        ->execute([$roomId, $roomId, $roomId]);
}

/** ป้ายห้องสำหรับแสดงผล เช่น A1-101 หรือ A1-101/A */
function room_label(?string $bcode, ?string $roomNo, ?string $unit = null): string
{
    if ($roomNo === null || $roomNo === '') {
        return '';
    }
    return ($bcode ? "$bcode-" : '') . $roomNo . ($unit ? "/$unit" : '');
}

/**
 * ที่พักที่จัดคนได้ ("slot"): ห้องที่ไม่มีห้องย่อย = 1 slot, ห้องที่มีห้องย่อย = 1 slot ต่อห้องย่อย
 * ถ้าระบุ $stayStart/$stayEnd จะนับเฉพาะผู้เข้าพักที่ช่วงวันที่เข้า-ออกทับซ้อนกับช่วงที่ขอ (ห้องจึงว่างคืนที่ไม่ชนกันได้แม้มีคนพักช่วงอื่น)
 * ผู้เข้าพักที่ไม่มีวันที่ระบุ (ข้อมูลเก่า) ถือว่าจองทับทุกช่วง (ปลอดภัยไว้ก่อน)
 * @return list<array{key:string,label:string,room_id:int,unit_id:?int,cap:int,used:int}>
 */
function slot_list(PDO $db, ?int $excludeGuest = null, ?string $stayStart = null, ?string $stayEnd = null, ?string $buildingCode = null, ?int $floor = null): array
{
    $ex = $excludeGuest ? ' AND g.id<>' . (int)$excludeGuest : '';
    $args = [];
    $dateCond = '';
    if ($stayStart !== null && $stayEnd !== null) {
        $dateCond = ' AND (g.stay_start IS NULL OR g.stay_start<?) AND (g.stay_end IS NULL OR g.stay_end>?)';
        $args = [$stayEnd, $stayStart];
    }
    $act = "g.status IN ('expected','checked_in')$ex$dateCond";
    $bCond = '';
    $bArgs = [];
    if ($buildingCode !== null) {
        $bCond .= ' AND b.code=?';
        $bArgs[] = $buildingCode;
    }
    if ($floor !== null) {
        $bCond .= ' AND r.floor=?';
        $bArgs[] = $floor;
    }
    $slots = [];
    $st = $db->prepare("SELECT r.id,r.room_no,r.beds,b.code bcode,
        (SELECT COUNT(*) FROM guests g WHERE g.room_id=r.id AND g.unit_id IS NULL AND $act) used,
        (SELECT COUNT(*) FROM room_units u WHERE u.room_id=r.id) nunits
        FROM rooms r JOIN buildings b ON b.id=r.building_id WHERE r.type='lodging' AND r.status NOT IN ('maintenance','unavailable')$bCond ORDER BY b.code,r.room_no");
    $st->execute([...$args, ...$bArgs]);
    $rooms = $st->fetchAll();
    $units = [];
    $st = $db->prepare("SELECT u.*,(SELECT COUNT(*) FROM guests g WHERE g.unit_id=u.id AND $act) used FROM room_units u ORDER BY u.label");
    $st->execute($args);
    foreach ($st->fetchAll() as $u) {
        $units[$u['room_id']][] = $u;
    }
    foreach ($rooms as $r) {
        if ($r['nunits'] > 0) {
            foreach ($units[$r['id']] ?? [] as $u) {
                $slots[] = ['key' => 'u' . $u['id'], 'label' => room_label($r['bcode'], $r['room_no'], $u['label']), 'room_id' => (int)$r['id'], 'unit_id' => (int)$u['id'], 'cap' => (int)$u['beds'], 'used' => (int)$u['used']];
            }
        } else {
            $slots[] = ['key' => 'r' . $r['id'], 'label' => room_label($r['bcode'], $r['room_no']), 'room_id' => (int)$r['id'], 'unit_id' => null, 'cap' => (int)$r['beds'], 'used' => (int)$r['used']];
        }
    }
    return $slots;
}

/**
 * ลำดับผู้เข้าพักคนนี้ในห้อง/ห้องย่อยที่จัดอยู่ (เช่น "1/2","2/2") เรียงตาม id เพื่อให้เห็นชัดว่าใครเป็นคนที่เท่าไรของห้องเดียวกัน
 * @return ?string null = ยังไม่ได้จัดห้อง
 */
function room_occupant_rank(PDO $db, array $g): ?string
{
    if (!$g['room_id']) {
        return null;
    }
    if ($g['unit_id']) {
        $capSt = $db->prepare('SELECT beds FROM room_units WHERE id=?');
        $capSt->execute([$g['unit_id']]);
        $w = 'unit_id=?';
        $args = [$g['room_id'], $g['unit_id']];
    } else {
        $capSt = $db->prepare('SELECT beds FROM rooms WHERE id=?');
        $capSt->execute([$g['room_id']]);
        $w = 'unit_id IS NULL';
        $args = [$g['room_id']];
    }
    $cap = (int)$capSt->fetchColumn();
    $st = $db->prepare("SELECT id FROM guests WHERE room_id=? AND $w AND status IN ('expected','checked_in') ORDER BY id");
    $st->execute($args);
    $occ = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $pos = array_search((int)$g['id'], $occ, true);
    return $pos === false ? null : ($pos + 1) . '/' . max($cap, count($occ));
}

function slot_lookup(PDO $db, string $key, int $excludeGuest, ?string $stayStart = null, ?string $stayEnd = null): ?array
{
    foreach (slot_list($db, $excludeGuest, $stayStart, $stayEnd) as $s) {
        if ($s['key'] === $key) {
            return $s;
        }
    }
    return null;
}

/** จำนวน migration ที่ยังไม่ได้รัน (ใช้เตือน admin หลังอัปเดตโค้ด) */
function pending_migrations(): int
{
    static $n = null;
    if ($n === null) {
        try {
            $n = count(array_filter((new Migrator(Db::pdo()))->status(), fn($r) => !$r['applied']));
        } catch (Throwable) {
            $n = 0;
        }
    }
    return $n;
}

// ---------- ประเภทห้อง / รูปห้องตัวอย่าง ----------
const ROOM_IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
const ROOM_IMAGE_MAX = 5 * 1024 * 1024;

function room_type_image_dir(): string
{
    $d = APP_ROOT . '/storage/room_types';
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
    return $d;
}

/** path ของไฟล์รูป (ตรวจชื่อไฟล์กันการอ้างอิงนอกโฟลเดอร์) หรือ null ถ้าไม่มี */
function room_type_image_path(?string $file): ?string
{
    if (!$file || !preg_match('/^[A-Za-z0-9_]+\.(jpe?g|png|webp|gif)$/', $file)) {
        return null;
    }
    $p = room_type_image_dir() . '/' . $file;
    return is_file($p) ? $p : null;
}

/** ตรวจว่าเป็นรูปจริง แล้วบันทึกเป็นรูปของประเภทห้อง (แทนรูปเดิม) — คืนชื่อไฟล์ใหม่ */
function room_type_save_image(PDO $db, int $typeId, string $bytes): string
{
    if (strlen($bytes) > ROOM_IMAGE_MAX) {
        throw new RuntimeException('ไฟล์รูปใหญ่เกิน 5 MB');
    }
    $info = @getimagesizefromstring($bytes);
    $ext = $info ? array_search($info['mime'], ROOM_IMAGE_TYPES, true) : false;
    if ($ext === false) {
        throw new RuntimeException('รองรับเฉพาะไฟล์รูป JPG, PNG, WEBP หรือ GIF');
    }
    $name = 'rt' . $typeId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (file_put_contents(room_type_image_dir() . '/' . $name, $bytes) === false) {
        throw new RuntimeException('บันทึกไฟล์รูปไม่สำเร็จ');
    }
    $old = $db->prepare('SELECT image FROM room_types WHERE id=?');
    $old->execute([$typeId]);
    if ($p = room_type_image_path((string)$old->fetchColumn())) {
        @unlink($p);
    }
    $db->prepare('UPDATE room_types SET image=? WHERE id=?')->execute([$name, $typeId]);
    return $name;
}

// ---------- ส่งออก/นำเข้าข้อมูลอาคาร ห้องพัก ห้องประชุม (ZIP: data.json + images/) ----------
function facilities_export_zip(PDO $db): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('เซิร์ฟเวอร์ไม่ได้เปิดใช้ PHP extension zip');
    }
    $types = [];
    $zipImgs = [];
    foreach ($db->query('SELECT * FROM room_types ORDER BY name')->fetchAll() as $t) {
        $img = null;
        if ($p = room_type_image_path($t['image'])) {
            $img = 'images/' . $t['image'];
            $zipImgs[$img] = $p;
        }
        $types[] = ['name' => $t['name'], 'note' => $t['note'], 'image' => $img];
    }
    $units = [];
    foreach ($db->query('SELECT room_id,label,beds FROM room_units ORDER BY label')->fetchAll() as $u) {
        $units[$u['room_id']][] = ['label' => $u['label'], 'beds' => (int)$u['beds']];
    }
    $rooms = [];
    foreach ($db->query('SELECT r.*, t.name type_name FROM rooms r LEFT JOIN room_types t ON t.id=r.room_type_id ORDER BY r.floor,r.room_no')->fetchAll() as $r) {
        $rooms[$r['building_id']][] = ['room_no' => $r['room_no'], 'floor' => (int)$r['floor'], 'beds' => (int)$r['beds'], 'type' => $r['type'],
            'room_type' => $r['type_name'], 'status' => $r['status'], 'status_note' => $r['status_note'], 'units' => $units[$r['id']] ?? []];
    }
    $buildings = [];
    foreach ($db->query('SELECT * FROM buildings ORDER BY code')->fetchAll() as $b) {
        $buildings[] = ['code' => $b['code'], 'name' => $b['name'], 'floors' => (int)$b['floors'], 'note' => $b['note'], 'rooms' => $rooms[$b['id']] ?? []];
    }
    $data = ['format' => 'vec-facilities', 'version' => 1, 'exported_at' => date('c'), 'room_types' => $types, 'buildings' => $buildings];

    $file = tempnam(sys_get_temp_dir(), 'fac');
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('สร้างไฟล์ ZIP ไม่สำเร็จ');
    }
    $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    foreach ($zipImgs as $name => $p) {
        $zip->addFile($p, $name);
    }
    $zip->close();
    return $file;
}

/**
 * นำเข้าแบบรวมข้อมูล (merge): จับคู่ประเภทห้องด้วยชื่อ อาคารด้วยรหัส ห้องด้วย (อาคาร, เลขห้อง) ห้องย่อยด้วยชื่อ
 * — ที่มีอยู่แล้วจะถูกอัปเดต ที่ยังไม่มีจะถูกเพิ่ม ไม่ลบข้อมูลเดิม และคงสถานะห้องเดิมไว้ (สถานะจากไฟล์ใช้กับห้องใหม่เท่านั้น)
 * @return array{types:int,buildings:int,rooms:int,units:int,images:int}
 */
function facilities_import_zip(PDO $db, string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('เซิร์ฟเวอร์ไม่ได้เปิดใช้ PHP extension zip');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('ไฟล์ไม่ใช่ ZIP ที่ถูกต้อง');
    }
    try {
        $data = json_decode((string)$zip->getFromName('data.json'), true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'vec-facilities') {
            throw new RuntimeException('ไม่พบ data.json ของระบบในไฟล์ ZIP (ต้องเป็นไฟล์ที่ส่งออกจากระบบนี้)');
        }
        $str = fn($v, int $max) => mb_substr(trim((string)$v), 0, $max);
        $opt = fn($v, int $max) => ($s = mb_substr(trim((string)$v), 0, $max)) !== '' ? $s : null;
        $n = ['types' => 0, 'buildings' => 0, 'rooms' => 0, 'units' => 0, 'images' => 0];
        $images = [];
        $typeId = [];
        $db->beginTransaction();
        try {
            $upType = $db->prepare('INSERT INTO room_types (name,note) VALUES (?,?) ON DUPLICATE KEY UPDATE note=VALUES(note), id=LAST_INSERT_ID(id)');
            foreach ((array)($data['room_types'] ?? []) as $t) {
                $name = $str($t['name'] ?? '', 100);
                if ($name === '') {
                    continue;
                }
                $upType->execute([$name, $opt($t['note'] ?? '', 255)]);
                $typeId[$name] = (int)$db->lastInsertId();
                $n['types']++;
                $img = (string)($t['image'] ?? '');
                if (str_starts_with($img, 'images/') && ($bytes = $zip->getFromName($img)) !== false) {
                    $images[$typeId[$name]] = $bytes;
                }
            }
            $findType = $db->prepare('SELECT id FROM room_types WHERE name=?');
            $upB = $db->prepare('INSERT INTO buildings (code,name,floors,note) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), floors=VALUES(floors), note=VALUES(note), id=LAST_INSERT_ID(id)');
            $upR = $db->prepare('INSERT INTO rooms (building_id,room_no,floor,beds,type,room_type_id,status,status_note) VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE floor=VALUES(floor), beds=VALUES(beds), type=VALUES(type), room_type_id=VALUES(room_type_id), id=LAST_INSERT_ID(id)');
            $upU = $db->prepare('INSERT INTO room_units (room_id,label,beds) VALUES (?,?,?) ON DUPLICATE KEY UPDATE beds=VALUES(beds)');
            foreach ((array)($data['buildings'] ?? []) as $b) {
                $code = $str($b['code'] ?? '', 20);
                if ($code === '') {
                    continue;
                }
                $upB->execute([$code, $str($b['name'] ?? '', 120) ?: $code, max(1, (int)($b['floors'] ?? 1)), $opt($b['note'] ?? '', 255)]);
                $bid = (int)$db->lastInsertId();
                $n['buildings']++;
                foreach ((array)($b['rooms'] ?? []) as $r) {
                    $no = $str($r['room_no'] ?? '', 30);
                    if ($no === '') {
                        continue;
                    }
                    $tid = null;
                    if (($tn = $str($r['room_type'] ?? '', 100)) !== '') {
                        if (!array_key_exists($tn, $typeId)) {
                            $findType->execute([$tn]);
                            $typeId[$tn] = ((int)$findType->fetchColumn()) ?: null;
                        }
                        $tid = $typeId[$tn];
                    }
                    $status = isset(ROOM_STATUS[$r['status'] ?? '']) ? $r['status'] : 'available';
                    $upR->execute([$bid, $no, max(1, (int)($r['floor'] ?? 1)), max(1, (int)($r['beds'] ?? 1)), ($r['type'] ?? '') === 'meeting' ? 'meeting' : 'lodging',
                        $tid, $status, $opt($r['status_note'] ?? '', 255)]);
                    $rid = (int)$db->lastInsertId();
                    $n['rooms']++;
                    foreach ((array)($r['units'] ?? []) as $u) {
                        if (($label = $str($u['label'] ?? '', 20)) !== '') {
                            $upU->execute([$rid, $label, max(1, (int)($u['beds'] ?? 1))]);
                            $n['units']++;
                        }
                    }
                    if (!empty($r['units'])) {
                        room_sync_beds($db, $rid);
                    }
                }
            }
            foreach ($images as $tid => $bytes) {
                room_type_save_image($db, $tid, $bytes);
                $n['images']++;
            }
            $db->commit();
        } catch (Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }
    } finally {
        $zip->close();
    }
    return $n;
}
