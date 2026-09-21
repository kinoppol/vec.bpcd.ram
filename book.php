<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('owner');
$db = Db::pdo();

// โหมดแก้ไข: แก้ได้เฉพาะคำขอของตนที่ยังรอตรวจสอบ
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$edit = null;
if ($editId) {
    $st = $db->prepare("SELECT * FROM projects WHERE id=? AND owner_id=? AND status='pending'");
    $st->execute([$editId, $me['id']]);
    $edit = $st->fetch() ?: null;
    if (!$edit) {
        flash('error', 'แก้ไขไม่ได้ — คำขอนี้ไม่ใช่ของคุณหรืออยู่ระหว่างตรวจสอบ/พิจารณาแล้ว');
        redirect('my_bookings.php');
    }
}

if (post_action() === 'save') {
    $p = [
        'name' => trim($_POST['name'] ?? ''), 'start_date' => $_POST['start_date'] ?? '', 'end_date' => $_POST['end_date'] ?? '',
        'meeting_room_id' => (int)($_POST['meeting_room_id'] ?? 0) ?: null,
    ];
    $n = fn(string $k) => max(0, (int)($_POST[$k] ?? 0));
    $ok = $p['name'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['end_date']) && $p['end_date'] >= $p['start_date'];
    if (!$ok) {
        flash('error', 'กรุณากรอกชื่อโครงการและวันที่ให้ถูกต้อง (วันสิ้นสุดต้องไม่ก่อนวันเริ่ม)');
    } else {
        if ($edit) {
            $db->prepare('UPDATE projects SET name=?,start_date=?,end_date=?,meeting_room_id=?,male=?,female=?,rooms_trainee=?,rooms_speaker=?,rooms_committee=?,note=? WHERE id=?')
                ->execute([$p['name'], $p['start_date'], $p['end_date'], $p['meeting_room_id'], $n('male'), $n('female'),
                    $n('rooms_trainee'), $n('rooms_speaker'), $n('rooms_committee'), trim($_POST['note'] ?? '') ?: null, $editId]);
            audit('แก้ไขคำขอจอง #' . $editId);
            notify('คำขอจองถูกแก้ไข: ' . $p['name'], "{$me['full_name']} แก้ไขคำขอจอง {$edit['booking_no']}", caretaker_emails(), true);
            flash('success', 'บันทึกการแก้ไขแล้ว');
            redirect('my_bookings.php');
        }
        $db->prepare('INSERT INTO projects (name,owner_id,start_date,end_date,meeting_room_id,male,female,rooms_trainee,rooms_speaker,rooms_committee,note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$p['name'], $me['id'], $p['start_date'], $p['end_date'], $p['meeting_room_id'], $n('male'), $n('female'),
                $n('rooms_trainee'), $n('rooms_speaker'), $n('rooms_committee'), trim($_POST['note'] ?? '') ?: null]);
        $id = (int)$db->lastInsertId();
        $no = 'BK' . date('ymd') . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE projects SET booking_no=? WHERE id=?')->execute([$no, $id]);
        $p['id'] = $id;
        audit("ยื่นคำขอจอง $no: {$p['name']}");
        notify("คำขอจองใหม่ $no", "{$me['full_name']} ยื่นคำขอ: {$p['name']} ({$p['start_date']} ถึง {$p['end_date']})", caretaker_emails(), true);
        flash('success', "บันทึกการจองเบื้องต้นแล้ว เลขที่ $no");
        if (project_conflicts($p)) {
            flash('warn', 'พบการจองห้องประชุมซ้อนช่วงเวลาเดียวกัน — รอผู้ดูแลตรวจสอบและแจ้งผล');
        }
        redirect('my_bookings.php');
    }
}

$meeting = $db->query("SELECT r.id,r.room_no,b.name bn FROM rooms r JOIN buildings b ON b.id=r.building_id WHERE r.type='meeting' AND r.status<>'maintenance' ORDER BY b.code,r.room_no")->fetchAll();
$v = fn(string $k, string $d = '') => e((string)($_POST[$k] ?? $edit[$k] ?? $d));
app_start($edit ? 'แก้ไขคำขอจอง' : 'จองห้องพักและห้องประชุม', $me, 'book');
?>
<form method="post" class="card" style="max-width:760px"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
  <label>ชื่อโครงการ</label><input type="text" name="name" value="<?= $v('name') ?>" required>
  <div class="row"><div><label>วันที่เริ่ม (เข้าพัก)</label><input type="date" name="start_date" value="<?= $v('start_date') ?>" required style="width:100%;border:1px solid var(--line);border-radius:8px;padding:9px 13px;font:inherit"></div>
  <div><label>วันที่สิ้นสุด (ออก)</label><input type="date" name="end_date" value="<?= $v('end_date') ?>" required style="width:100%;border:1px solid var(--line);border-radius:8px;padding:9px 13px;font:inherit"></div></div>
  <label>ห้องประชุมที่ต้องการ</label><select name="meeting_room_id"><option value="">— ไม่ใช้ห้องประชุม —</option>
    <?php foreach ($meeting as $m): ?><option value="<?= $m['id'] ?>" <?= ($_POST['meeting_room_id'] ?? $edit['meeting_room_id'] ?? '') == $m['id'] ? 'selected' : '' ?>><?= e($m['bn'] . ' · ' . $m['room_no']) ?></option><?php endforeach; ?></select>
  <div class="row"><div><label>ผู้เข้าพักชาย (คน)</label><input type="number" min="0" name="male" value="<?= $v('male', '0') ?>"></div><div><label>ผู้เข้าพักหญิง (คน)</label><input type="number" min="0" name="female" value="<?= $v('female', '0') ?>"></div></div>
  <div class="row" style="grid-template-columns:1fr 1fr 1fr"><div><label>ห้องผู้เข้าอบรม</label><input type="number" min="0" name="rooms_trainee" value="<?= $v('rooms_trainee', '0') ?>"></div>
  <div><label>ห้องวิทยากร</label><input type="number" min="0" name="rooms_speaker" value="<?= $v('rooms_speaker', '0') ?>"></div>
  <div><label>ห้องกรรมการดำเนินโครงการ</label><input type="number" min="0" name="rooms_committee" value="<?= $v('rooms_committee', '0') ?>"></div></div>
  <label>หมายเหตุ</label><input type="text" name="note" value="<?= $v('note') ?>">
  <div class="alert alert-warn" style="margin-top:14px">ระบุแยกชาย–หญิง ยกเว้นกรณีคู่สมรส ระบบจะตรวจสอบความซ้อนทับของห้องประชุมโดยอัตโนมัติหลังกดบันทึก</div>
  <button class="btn"><?= $edit ? 'บันทึกการแก้ไข' : 'บันทึกจองเบื้องต้น' ?></button>
</form>
<?php app_end();
