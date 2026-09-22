<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('caretaker');
$db = Db::pdo();

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_guests.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF"); // ให้ Excel อ่านภาษาไทยถูกต้อง
    fputcsv($o, ['ชื่อ-สกุล', 'เพศ(ช/ญ)', 'ประเภท', 'ห้องที่ระบุ', 'หมายเหตุ', 'เลขบัตรประชาชน']);
    fputcsv($o, ['สมชาย ใจดี', 'ช', GUEST_TYPE['trainee'], '', '', '']);
    fputcsv($o, ['สมหญิง มีสุข', 'ญ', GUEST_TYPE['speaker'], '', '', '']);
    exit;
}

$pid = (int)($_GET['project'] ?? $_POST['project'] ?? 0);
$proj = null;
if ($pid) {
    $st = $db->prepare('SELECT * FROM projects WHERE id=?');
    $st->execute([$pid]);
    $proj = $st->fetch() ?: null;
}

/** เพิ่มผู้เข้าพัก — วันเข้า-ออกถ้าไม่ระบุ ใช้ค่าเริ่มต้นจากวันโครงการ (เข้าพักล่วงหน้า 1 คืน, ไม่จองคืนวันสุดท้าย) */
function add_guest(PDO $db, int $pid, array $g, ?array $proj): void
{
    $type = isset(GUEST_TYPE[$g['type'] ?? '']) ? $g['type'] : 'trainee';
    [$defStart, $defEnd] = $proj ? guest_default_stay($proj) : [null, null];
    $ss = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['stay_start'] ?? '')) ? $g['stay_start'] : $defStart;
    $se = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['stay_end'] ?? '')) ? $g['stay_end'] : $defEnd;
    $db->prepare('INSERT INTO guests (project_id,name,gender,type,id_card,room_requested,stay_start,stay_end,note) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$pid, trim($g['name']), ($g['gender'] ?? 'M') === 'F' ? 'F' : 'M', $type, preg_replace('/\D/', '', (string)($g['id_card'] ?? '')) ?: null, trim($g['room_requested'] ?? '') ?: null, $ss, $se, trim($g['note'] ?? '') ?: null]);
}

if ($pid && $proj) {
    [$defStart, $defEnd] = guest_default_stay($proj);
    switch (post_action()) {
        case 'add':
            if (trim($_POST['name'] ?? '') !== '') {
                add_guest($db, $pid, $_POST, $proj);
                audit("เพิ่มผู้เข้าพัก {$_POST['name']} (โครงการ #$pid)");
            }
            break;
        case 'import':
            $n = 0;
            $tmp = $_FILES['csv']['tmp_name'] ?? '';
            if ($tmp && is_uploaded_file($tmp) && ($h = fopen($tmp, 'r'))) {
                $bom = fread($h, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($h);
                }
                $map = ['ช' => 'M', 'M' => 'M', 'ชาย' => 'M', 'ญ' => 'F', 'F' => 'F', 'หญิง' => 'F'];
                $tmap = array_flip(GUEST_TYPE) + ['trainee' => 'trainee', 'speaker' => 'speaker', 'committee' => 'committee', 'exec' => 'exec'];
                while (($r = fgetcsv($h)) !== false) {
                    if (trim($r[0] ?? '') === '' || trim($r[0]) === 'ชื่อ-สกุล' || trim($r[0]) === 'ชื่อ–สกุล') {
                        continue;
                    }
                    add_guest($db, $pid, ['name' => $r[0], 'gender' => $map[trim($r[1] ?? '')] ?? 'M',
                        'type' => $tmap[trim($r[2] ?? '')] ?? 'trainee', 'room_requested' => $r[3] ?? '', 'note' => $r[4] ?? '', 'id_card' => $r[5] ?? ''], $proj);
                    $n++;
                }
                fclose($h);
            }
            audit("นำเข้ารายชื่อ CSV $n คน (โครงการ #$pid)");
            flash('success', "นำเข้า $n รายการ");
            break;
        case 'assign':
            // ค่าที่เลือกคือ "r<ห้อง>" (ห้องไม่มีห้องย่อย) หรือ "u<ห้องย่อย>"
            $key = (string)($_POST['slot'] ?? '');
            $gid = (int)$_POST['id'];
            $rid = $uid = null;
            if ($key !== '') {
                $gst = $db->prepare('SELECT stay_start,stay_end FROM guests WHERE id=? AND project_id=?');
                $gst->execute([$gid, $pid]);
                $gr = $gst->fetch();
                if (!$gr) {
                    flash('error', 'ไม่พบผู้เข้าพัก');
                    break;
                }
                $slot = slot_lookup($db, $key, $gid, $gr['stay_start'] ?? $defStart, $gr['stay_end'] ?? $defEnd);
                if (!$slot) {
                    flash('error', 'ห้องนี้ไม่สามารถจัดได้');
                    break;
                }
                if ($slot['used'] >= $slot['cap']) {
                    flash('error', "ห้อง {$slot['label']} เต็มแล้วในช่วงวันที่เข้าพักนี้ ({$slot['used']}/{$slot['cap']} เตียง)");
                    break;
                }
                [$rid, $uid] = [$slot['room_id'], $slot['unit_id']];
            }
            $db->prepare('UPDATE guests SET room_id=?, unit_id=? WHERE id=? AND project_id=?')->execute([$rid, $uid, $gid, $pid]);
            if ($rid) {
                $db->prepare("UPDATE rooms SET status='reserved' WHERE id=? AND status='available'")->execute([$rid]);
            }
            audit('จัดห้องผู้เข้าพัก #' . $gid);
            break;
        case 'update_stay':
            // แก้ไขวันเข้า-ออกของผู้เข้าพักรายคน (ค่าว่าง = ใช้ค่าเริ่มต้นของโครงการ)
            $gid = (int)$_POST['id'];
            $ss = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['stay_start'] ?? '')) ? $_POST['stay_start'] : $defStart;
            $se = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['stay_end'] ?? '')) ? $_POST['stay_end'] : $defEnd;
            if ($ss > $se) {
                flash('error', 'วันเข้าพักต้องไม่หลังวันออก');
                break;
            }
            $db->prepare('UPDATE guests SET stay_start=?, stay_end=? WHERE id=? AND project_id=?')->execute([$ss, $se, $gid, $pid]);
            audit('แก้ไขวันเข้าพัก #' . $gid);
            break;
        case 'update_name':
            // ใช้แก้ชื่อชั่วคราว ("วิทยากรคนที่ 1") ให้เป็นชื่อจริง และแก้เพศภายหลังได้ (เช่น สร้างอัตโนมัติผิดเพศ)
            $gid = (int)$_POST['id'];
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                flash('error', 'ชื่อห้ามว่าง');
                break;
            }
            $gd = ($_POST['gender'] ?? 'M') === 'F' ? 'F' : 'M';
            $db->prepare('UPDATE guests SET name=?, gender=? WHERE id=? AND project_id=?')->execute([mb_substr($name, 0, 150), $gd, $gid, $pid]);
            audit('แก้ไขชื่อ/เพศผู้เข้าพัก #' . $gid);
            break;
        case 'generate':
            // สร้างผู้เข้าพักชื่อชั่วคราว (เช่น "วิทยากรคนที่ 1") ไว้จองห้องล่วงหน้า ก่อนทราบชื่อจริง
            $type = isset(GUEST_TYPE[$_POST['type'] ?? '']) ? $_POST['type'] : 'trainee';
            $cnt = max(0, min(200, (int)($_POST['count'] ?? 0)));
            $gender = ($_POST['gender'] ?? 'M') === 'F' ? 'F' : 'M';
            if ($cnt > 0) {
                $cst = $db->prepare('SELECT COUNT(*) FROM guests WHERE project_id=? AND type=?');
                $cst->execute([$pid, $type]);
                $start = (int)$cst->fetchColumn() + 1;
                for ($i = 0; $i < $cnt; $i++) {
                    add_guest($db, $pid, ['name' => GUEST_TYPE[$type] . 'คนที่ ' . ($start + $i), 'gender' => $gender, 'type' => $type], $proj);
                }
                audit("สร้างชื่อชั่วคราว $cnt คน ประเภท $type (โครงการ #$pid)");
                flash('success', "สร้างชื่อชั่วคราว $cnt รายการ — แก้เป็นชื่อจริงได้ภายหลังที่ช่องชื่อในตาราง");
            }
            break;
        case 'generate_from_project':
            // สร้างชื่อชั่วคราวให้ครบตามจำนวนห้องที่ระบุไว้ในรายละเอียดโครงการ (เติมเฉพาะส่วนที่ยังขาด นับแยกตามโครงการ ไม่ปะปนกัน)
            // ค่าเริ่มต้นคนต่อห้อง = 1 (1 ห้องที่ระบุ = 1 คน) แต่ปรับได้ต่อประเภท เผื่อห้องไม่พอต้องพักรวมห้องละหลายคน
            // เพศ: ไล่เติมตามจำนวนชาย/หญิงที่แจ้งไว้ในโครงการ (หักจากที่มีอยู่แล้ว) จนครบ ถ้าเกินจำนวนที่แจ้งไว้ ตั้งเป็นชายไปก่อน แก้ทีหลังได้ที่ตาราง
            $exG = $db->prepare("SELECT gender, COUNT(*) c FROM guests WHERE project_id=? GROUP BY gender");
            $exG->execute([$pid]);
            $remM = (int)$proj['male'];
            $remF = (int)$proj['female'];
            foreach ($exG->fetchAll() as $r) {
                if ($r['gender'] === 'M') {
                    $remM -= (int)$r['c'];
                } else {
                    $remF -= (int)$r['c'];
                }
            }
            $remM = max(0, $remM);
            $remF = max(0, $remF);
            $map = ['trainee' => (int)$proj['rooms_trainee'], 'speaker' => (int)$proj['rooms_speaker'], 'committee' => (int)$proj['rooms_committee']];
            $total = 0;
            foreach ($map as $type => $rooms) {
                if ($rooms <= 0) {
                    continue;
                }
                $perRoom = max(1, min(4, (int)($_POST['per_' . $type] ?? 1)));
                $target = $rooms * $perRoom;
                $cst = $db->prepare('SELECT COUNT(*) FROM guests WHERE project_id=? AND type=?');
                $cst->execute([$pid, $type]);
                $existing = (int)$cst->fetchColumn();
                $need = $target - $existing;
                for ($i = 0; $i < $need; $i++) {
                    if ($remM > 0) {
                        $gd = 'M';
                        $remM--;
                    } elseif ($remF > 0) {
                        $gd = 'F';
                        $remF--;
                    } else {
                        $gd = 'M';
                    }
                    add_guest($db, $pid, ['name' => GUEST_TYPE[$type] . 'คนที่ ' . ($existing + $i + 1), 'gender' => $gd, 'type' => $type], $proj);
                }
                $total += max(0, $need);
            }
            if ($total > 0) {
                audit("สร้างชื่อชั่วคราวตามจำนวนในโครงการ $total คน (โครงการ #$pid)");
                flash('success', "สร้างชื่อชั่วคราว $total รายการตามจำนวนที่ระบุในโครงการ — แก้เป็นชื่อจริงได้ภายหลังที่ช่องชื่อในตาราง");
            } else {
                flash('error', 'มีผู้เข้าพักครบตามจำนวนที่ระบุในโครงการแล้ว หรือโครงการนี้ไม่ได้ระบุจำนวนห้องไว้');
            }
            break;
        case 'delete':
            $db->prepare('DELETE FROM guests WHERE id=? AND project_id=?')->execute([(int)$_POST['id'], $pid]);
            audit('ลบผู้เข้าพัก #' . (int)$_POST['id']);
            break;
        case 'bulk_assign':
            // จัดห้องให้หลายคนพร้อมกัน: เลือกอาคาร (และชั้นถ้าต้องการ) ระบบจัดห้องว่างให้ตามลำดับที่เลือกจนครบ เหลือเท่าไรแจ้งกลับ
            $ids = array_values(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))));
            $bc = trim((string)($_POST['building_code'] ?? ''));
            $floor = trim((string)($_POST['floor'] ?? '')) !== '' ? (int)$_POST['floor'] : null;
            if (!$ids) {
                flash('error', 'ยังไม่ได้เลือกผู้เข้าพัก');
                break;
            }
            if ($bc === '') {
                flash('error', 'ระบุอาคารที่ต้องการจัดให้');
                break;
            }
            $bst = $db->prepare('SELECT id FROM buildings WHERE code=?');
            $bst->execute([$bc]);
            if (!$bst->fetch()) {
                flash('error', 'ไม่พบรหัสอาคารนี้');
                break;
            }
            $gst = $db->prepare('SELECT id,name,stay_start,stay_end FROM guests WHERE id=? AND project_id=?');
            $ug = $db->prepare('UPDATE guests SET room_id=?, unit_id=? WHERE id=?');
            $ur = $db->prepare("UPDATE rooms SET status='reserved' WHERE id=? AND status='available'");
            $done = 0;
            $fail = [];
            foreach ($ids as $gid) {
                $gst->execute([$gid, $pid]);
                $g = $gst->fetch();
                if (!$g) {
                    continue;
                }
                $ss = $g['stay_start'] ?? $defStart;
                $se = $g['stay_end'] ?? $defEnd;
                $picked = null;
                foreach (slot_list($db, $gid, $ss, $se, $bc, $floor) as $sl) {
                    if ($sl['used'] < $sl['cap']) {
                        $picked = $sl;
                        break;
                    }
                }
                if (!$picked) {
                    $fail[] = $g['name'];
                    continue;
                }
                $ug->execute([$picked['room_id'], $picked['unit_id'], $gid]);
                $ur->execute([$picked['room_id']]);
                $done++;
            }
            audit("จัดห้องพักกลุ่ม $done คน อาคาร $bc" . ($floor !== null ? " ชั้น $floor" : '') . " (โครงการ #$pid)");
            if ($fail) {
                flash('error', "จัดห้องให้ $done คน — ห้องในอาคาร/ชั้นที่เลือกไม่พอสำหรับอีก " . count($fail) . ' คน: ' . implode(', ', array_slice($fail, 0, 15)) . (count($fail) > 15 ? ' …' : '') . ' — เลือกอาคารหรือชั้นอื่นแล้วจัดต่อได้');
            } else {
                flash('success', "จัดห้องให้ $done คน เรียบร้อย");
            }
            break;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        redirect('guests.php?project=' . $pid . ($_GET['ftype'] ?? '' ? '&ftype=' . urlencode($_GET['ftype']) : '') . ($_GET['fgender'] ?? '' ? '&fgender=' . urlencode($_GET['fgender']) : '') . ($_GET['fassigned'] ?? '' ? '&fassigned=' . urlencode($_GET['fassigned']) : ''));
    }
}

$projects = $db->query("SELECT id,name,start_date FROM projects WHERE status IN ('pending','checking','approved') ORDER BY start_date DESC")->fetchAll();
$buildingsAll = $db->query("SELECT b.code,b.name,b.floors FROM buildings b WHERE EXISTS (SELECT 1 FROM rooms r WHERE r.building_id=b.id AND r.type='lodging') ORDER BY b.code")->fetchAll();
$guests = [];
$totalGuests = 0;
[$defStart, $defEnd] = $proj ? guest_default_stay($proj) : [null, null];
// ตัวกรองตาราง: ประเภท/เพศ/สถานะการจัดห้อง (ไม่กระทบข้อมูลจริง แค่กรองการแสดงผล)
$ftype = isset(GUEST_TYPE[$_GET['ftype'] ?? '']) ? $_GET['ftype'] : '';
$fgender = in_array($_GET['fgender'] ?? '', ['M', 'F'], true) ? $_GET['fgender'] : '';
$fassigned = in_array($_GET['fassigned'] ?? '', ['yes', 'no'], true) ? $_GET['fassigned'] : '';
if ($pid) {
    $tst = $db->prepare('SELECT COUNT(*) FROM guests WHERE project_id=?');
    $tst->execute([$pid]);
    $totalGuests = (int)$tst->fetchColumn();
    $w = 'g.project_id=?';
    $args = [$pid];
    if ($ftype !== '') {
        $w .= ' AND g.type=?';
        $args[] = $ftype;
    }
    if ($fgender !== '') {
        $w .= ' AND g.gender=?';
        $args[] = $fgender;
    }
    if ($fassigned === 'yes') {
        $w .= ' AND g.room_id IS NOT NULL';
    } elseif ($fassigned === 'no') {
        $w .= ' AND g.room_id IS NULL';
    }
    $st = $db->prepare("SELECT g.*, r.room_no, b.code bcode FROM guests g LEFT JOIN rooms r ON r.id=g.room_id LEFT JOIN buildings b ON b.id=r.building_id WHERE $w ORDER BY g.id");
    $st->execute($args);
    $guests = $st->fetchAll();
}

app_start('จัดผู้เข้าพักและเลขห้อง', $me, 'guests');
?>
<form method="get" class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><label style="margin:0">โครงการ</label>
  <select name="project" onchange="this.form.submit()" style="max-width:520px"><option value="">— เลือกโครงการ —</option>
  <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= $p['id'] == $pid ? 'selected' : '' ?>><?= e($p['name'] . ' (' . thai_date($p['start_date']) . ')') ?></option><?php endforeach; ?></select></form>
<?php if ($proj): ?>
<div class="card"><b><?= e($proj['name']) ?></b> <?= badge(PROJECT_STATUS, $proj['status']) ?>
  <div style="font-size:12.5px;color:#8A8F98;margin-top:4px"><?= e(thai_date($proj['start_date'])) ?> – <?= e(thai_date($proj['end_date'])) ?> · แจ้งไว้ ชาย <?= $proj['male'] ?> หญิง <?= $proj['female'] ?> · ลงรายชื่อแล้ว <?= $totalGuests ?> คน</div>
  <div style="font-size:12.5px;color:#8A8F98;margin-top:2px">ค่าเริ่มต้นวันเข้าพัก (ไม่ระบุรายคน): เข้า <?= e(thai_date($defStart)) ?> – ออก <?= e(thai_date($defEnd)) ?> (ล่วงหน้า 1 คืนก่อนวันโครงการ, ไม่จองคืนวันสุดท้าย)</div>
  <div class="actions" style="margin-top:10px">
    <button type="button" class="btn" data-open="dlgManageGuests">จัดการรายชื่อผู้เข้าพัก</button>
    <a class="btn ghost" href="<?= e(url('reports.php?r=guests&export=1&project=' . $pid)) ?>">ดาวน์โหลดรายชื่อ (Excel CSV)</a>
  </div>
</div>
<dialog id="dlgManageGuests" class="modal" style="width:min(640px,calc(100vw - 32px))">
  <div class="modal-h"><b>จัดการรายชื่อผู้เข้าพัก</b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
  <label>เลือกวิธี</label>
  <select id="gmPick" style="margin-bottom:16px">
    <option value="add">เพิ่มผู้เข้าพัก (ทีละคน)</option>
    <option value="import">นำเข้ารายชื่อ (CSV/Excel)</option>
    <option value="generate_from_project">สร้างชื่อชั่วคราวตามจำนวนในโครงการ</option>
    <option value="generate">สร้างชื่อชั่วคราวเอง (ระบุประเภท/จำนวน)</option>
  </select>
  <div data-gm="add">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="project" value="<?= $pid ?>">
  <div class="row"><div><label>ชื่อ–สกุล</label><input type="text" name="name" required></div>
  <div><label>เพศ</label><select name="gender"><option value="M">ชาย</option><option value="F">หญิง</option></select></div>
  <div><label>ประเภท</label><select name="type"><?php foreach (GUEST_TYPE as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div><label>ห้องที่ระบุ</label><input type="text" name="room_requested"></div>
  <div><label>เลขบัตรประชาชน (ถ้ามี)</label><input type="text" name="id_card" maxlength="13"></div></div>
  <div class="row"><div><label>วันเข้าพัก (ว่าง=ค่าเริ่มต้น)</label><input type="date" name="stay_start" value="<?= e($defStart) ?>"></div>
  <div><label>วันออก (ว่าง=ค่าเริ่มต้น)</label><input type="date" name="stay_end" value="<?= e($defEnd) ?>"></div></div>
  <label>หมายเหตุ/เงื่อนไข</label><input type="text" name="note"><p><button class="btn">เพิ่ม</button></p></form>
  </div>
  <div data-gm="import" hidden>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="project" value="<?= $pid ?>">
  <p style="font-size:12.5px;color:#5B5450">ใน Excel เลือก บันทึกเป็น → CSV UTF-8 คอลัมน์: <code>ชื่อ-สกุล, เพศ(ช/ญ), ประเภท, ห้องที่ระบุ, หมายเหตุ, เลขบัตรประชาชน</code> (วันเข้า-ออกใช้ค่าเริ่มต้นของโครงการเสมอ แก้รายคนได้ในตารางด้านล่าง)</p>
  <p><a class="btn ghost" href="<?= e(url('guests.php?template=1')) ?>">ดาวน์โหลดเทมเพลต (CSV)</a></p>
  <input type="file" name="csv" accept=".csv" required><p><button class="btn">นำเข้า</button></p></form>
  </div>
  <div data-gm="generate_from_project" hidden>
  <p style="font-size:12.5px;color:#5B5450">ยังไม่ทราบชื่อจริง? สร้างเป็น "วิทยากรคนที่ 1", "กรรมการคนที่ 2" ฯลฯ ไว้จองห้องก่อน แล้วแก้เป็นชื่อจริงภายหลังที่ช่องชื่อในตาราง (แต่ละโครงการนับเลขแยกกัน ไม่ปะปนกัน)</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate_from_project"><input type="hidden" name="project" value="<?= $pid ?>">
    <p style="font-size:12.5px;color:#5B5450;margin:0 0 8px">สร้างให้ครบตามจำนวนห้องที่ระบุตอนขอจอง เติมเฉพาะส่วนที่ยังขาด — ปกติ 1 ห้อง = 1 คน แต่ถ้าห้องไม่พอ ปรับ "คนต่อห้อง" เป็น 2 ขึ้นไปให้พักรวมห้องได้</p>
    <div class="row" style="grid-template-columns:1fr 1fr 1fr">
      <div><label>ผู้เข้าอบรม (<?= (int)$proj['rooms_trainee'] ?> ห้อง)</label><input type="number" name="per_trainee" value="1" min="1" max="4" <?= $proj['rooms_trainee'] ? '' : 'disabled' ?>></div>
      <div><label>วิทยากร (<?= (int)$proj['rooms_speaker'] ?> ห้อง)</label><input type="number" name="per_speaker" value="1" min="1" max="4" <?= $proj['rooms_speaker'] ? '' : 'disabled' ?>></div>
      <div><label>กรรมการ (<?= (int)$proj['rooms_committee'] ?> ห้อง)</label><input type="number" name="per_committee" value="1" min="1" max="4" <?= $proj['rooms_committee'] ? '' : 'disabled' ?>></div>
    </div>
    <p style="font-size:11px;color:#8A8F98;margin:4px 0 8px">คนต่อห้อง = จำนวนคนที่ให้พักรวมกันต่อ 1 ห้องที่ขอไว้</p>
    <button class="btn">สร้างตามจำนวนในโครงการ</button></form>
  </div>
  <div data-gm="generate" hidden>
  <p style="font-size:12.5px;color:#5B5450">ยังไม่ทราบชื่อจริง? สร้างเป็น "วิทยากรคนที่ 1" ฯลฯ ไว้จองห้องก่อน แล้วแก้เป็นชื่อจริงภายหลังที่ช่องชื่อในตาราง</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="project" value="<?= $pid ?>">
  <div class="row"><div><label>ประเภท</label><select name="type"><?php foreach (GUEST_TYPE as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div><label>จำนวน (คน)</label><input type="number" name="count" min="1" max="200" value="1"></div>
  <div><label>เพศ (แก้ทีหลังได้)</label><select name="gender"><option value="M">ชาย</option><option value="F">หญิง</option></select></div></div>
  <p><button class="btn ghost">สร้างชื่อชั่วคราว</button></p></form>
  </div>
</dialog>
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px">
<form method="get" class="card" style="flex:1 1 380px;margin-bottom:0;display:flex;gap:8px;align-items:center;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap"><label style="margin:0;flex:none">ตัวกรอง</label><input type="hidden" name="project" value="<?= $pid ?>">
  <select name="ftype" onchange="this.form.submit()" style="width:auto;flex:none"><option value="">ทุกประเภท</option><?php foreach (GUEST_TYPE as $k => $l): ?><option value="<?= $k ?>" <?= $ftype === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="fgender" onchange="this.form.submit()" style="width:auto;flex:none"><option value="">ทุกเพศ</option><option value="M" <?= $fgender === 'M' ? 'selected' : '' ?>>ชาย</option><option value="F" <?= $fgender === 'F' ? 'selected' : '' ?>>หญิง</option></select>
  <select name="fassigned" onchange="this.form.submit()" style="width:auto;flex:none"><option value="">ทุกสถานะการจัดห้อง</option><option value="yes" <?= $fassigned === 'yes' ? 'selected' : '' ?>>จัดให้แล้ว</option><option value="no" <?= $fassigned === 'no' ? 'selected' : '' ?>>ยังไม่ได้จัดสรร</option></select>
  <?php if ($ftype !== '' || $fgender !== '' || $fassigned !== ''): ?><a class="btn ghost" style="flex:none" href="<?= e(url('guests.php?project=' . $pid)) ?>">ล้างตัวกรอง</a><?php endif; ?>
  <span style="font-size:12.5px;color:#8A8F98;flex:none">แสดง <?= count($guests) ?> จาก <?= $totalGuests ?> คน</span></form>
<form id="bulkAssignForm" method="post" class="card" style="flex:1 1 380px;margin-bottom:0;display:flex;gap:8px;align-items:center;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_assign"><input type="hidden" name="project" value="<?= $pid ?>">
  <b style="font-size:12.5px;flex:none">จัดห้องให้ที่เลือกไว้:</b>
  <span id="bulkCount" style="font-size:12.5px;color:#8A8F98;flex:none">เลือกไว้ 0 คน</span>
  <select name="building_code" id="bulkBuilding" required style="width:auto;flex:none"><option value="">— อาคาร —</option><?php foreach ($buildingsAll as $b): ?><option value="<?= e($b['code']) ?>" data-floors="<?= (int)$b['floors'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
  <select name="floor" id="bulkFloor" style="width:auto;flex:none"><option value="">ทุกชั้น</option></select>
  <button type="submit" class="btn" id="bulkBtn" disabled style="flex:none" title="ระบบจะจัดห้องว่างในอาคาร/ชั้นที่เลือกให้ตามลำดับจนครบ ถ้าห้องไม่พอจะแจ้งจำนวนที่เหลือ ให้เลือกอาคาร/ชั้นอื่นจัดต่อได้">จัดห้องให้ผู้ที่เลือก</button></form>
</div>
<div class="card" style="padding:0;overflow:hidden"><table><tr><th><input type="checkbox" id="chkAll"></th><th>ชื่อ–สกุล</th><th>เพศ</th><th>ประเภท</th><th>ห้องที่ระบุ</th><th>วันเข้า–ออก</th><th>ห้องที่จัดจริง</th><th>หมายเหตุ</th><th></th></tr>
<?php foreach ($guests as $g):
    $gss = $g['stay_start'] ?? $defStart;
    $gse = $g['stay_end'] ?? $defEnd;
    $slots = slot_list($db, (int)$g['id'], $gss, $gse);
?><tr><td><input type="checkbox" class="gchk" name="ids[]" value="<?= $g['id'] ?>" form="bulkAssignForm"></td><td><form id="fn<?= $g['id'] ?>" method="post" style="display:flex"><?= csrf_field() ?><input type="hidden" name="action" value="update_name"><input type="hidden" name="project" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= $g['id'] ?>">
  <input type="text" name="name" value="<?= e($g['name']) ?>" style="min-width:150px;font-weight:600" onchange="this.form.submit()"></form></td><td><select name="gender" form="fn<?= $g['id'] ?>" onchange="this.form.submit()" style="width:auto"><option value="M" <?= $g['gender'] === 'M' ? 'selected' : '' ?>>ชาย</option><option value="F" <?= $g['gender'] === 'F' ? 'selected' : '' ?>>หญิง</option></select></td><td><?= e(GUEST_TYPE[$g['type']]) ?></td><td><?= e($g['room_requested']) ?></td>
<td><form method="post" style="display:flex;gap:4px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="update_stay"><input type="hidden" name="project" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= $g['id'] ?>">
  <input type="date" name="stay_start" value="<?= e($gss) ?>" style="width:130px" onchange="this.form.submit()">
  <input type="date" name="stay_end" value="<?= e($gse) ?>" style="width:130px" onchange="this.form.submit()"></form></td>
<td><form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="project" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= $g['id'] ?>">
  <select name="slot" onchange="this.form.submit()" style="min-width:150px"><option value="">— ยังไม่จัด —</option>
  <?php $cur = $g['unit_id'] ? 'u' . $g['unit_id'] : ($g['room_id'] ? 'r' . $g['room_id'] : ''); $curRank = room_occupant_rank($db, $g); foreach ($slots as $sl): $isCur = $sl['key'] === $cur; $full = $sl['used'] >= $sl['cap'] && !$isCur; $cnt = $isCur && $curRank ? $curRank : "{$sl['used']}/{$sl['cap']}"; ?><option value="<?= $sl['key'] ?>" <?= $isCur ? 'selected' : '' ?> <?= $full ? 'disabled' : '' ?>><?= e($sl['label'] . " ($cnt)") ?></option><?php endforeach; ?></select></form></td>
<td style="font-size:12px;color:#8A8F98"><?= e($g['note']) ?></td>
<td><form method="post" data-confirm="ลบรายการนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="project" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn ghost">ลบ</button></form></td></tr>
<?php endforeach; if (!$guests): ?><tr><td colspan="9"><?= $totalGuests ? 'ไม่มีรายชื่อตรงตามตัวกรอง' : 'ยังไม่มีรายชื่อ' ?></td></tr><?php endif; ?></table></div>
<script>
var gmPick=document.getElementById('gmPick');
function gmShow(v){document.querySelectorAll('[data-gm]').forEach(function(p){p.hidden=p.dataset.gm!==v})}
if(gmPick){gmPick.onchange=function(){gmShow(gmPick.value)};gmShow(gmPick.value)}
document.querySelectorAll('[data-open]').forEach(function(b){b.onclick=function(){if(gmPick){gmPick.value='add';gmShow('add')}document.getElementById(b.dataset.open).showModal()}});
document.querySelectorAll('dialog.modal').forEach(function(d){
  d.addEventListener('click',function(e){if(e.target===d)d.close()});
  d.querySelectorAll('[data-close]').forEach(function(x){x.onclick=function(){d.close()}});
});
// เลือกหลายคนพร้อมกัน: นับจำนวนที่ติ๊ก, เปิด/ปิดปุ่ม, ติ๊กทั้งหมด, และเติมตัวเลือกชั้นตามอาคารที่เลือก
(function(){
  var boxes=[].slice.call(document.querySelectorAll('.gchk')), chkAll=document.getElementById('chkAll'),
    cnt=document.getElementById('bulkCount'), btn=document.getElementById('bulkBtn'),
    bBuild=document.getElementById('bulkBuilding'), bFloor=document.getElementById('bulkFloor');
  function refresh(){
    var n=boxes.filter(function(b){return b.checked}).length;
    cnt.textContent='เลือกไว้ '+n+' คน';
    btn.disabled=!(n>0&&bBuild.value);
  }
  boxes.forEach(function(b){b.onchange=refresh});
  if(chkAll)chkAll.onchange=function(){boxes.forEach(function(b){b.checked=chkAll.checked});refresh()};
  if(bBuild)bBuild.onchange=function(){
    var f=parseInt(bBuild.options[bBuild.selectedIndex].dataset.floors||'0',10);
    bFloor.innerHTML='<option value="">ทุกชั้น</option>';
    for(var i=1;i<=f;i++){var o=document.createElement('option');o.value=i;o.textContent='ชั้น '+i;bFloor.appendChild(o)}
    refresh();
  };
  refresh();
})();
</script>
<?php endif; ?>
<?php app_end();
