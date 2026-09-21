<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('caretaker');
$db = Db::pdo();
$pid = (int)($_GET['project'] ?? $_POST['project'] ?? 0);

function add_guest(PDO $db, int $pid, array $g): void
{
    $type = isset(GUEST_TYPE[$g['type'] ?? '']) ? $g['type'] : 'trainee';
    $db->prepare('INSERT INTO guests (project_id,name,gender,type,id_card,room_requested,note) VALUES (?,?,?,?,?,?,?)')
        ->execute([$pid, trim($g['name']), ($g['gender'] ?? 'M') === 'F' ? 'F' : 'M', $type, preg_replace('/\D/', '', (string)($g['id_card'] ?? '')) ?: null, trim($g['room_requested'] ?? '') ?: null, trim($g['note'] ?? '') ?: null]);
}

if ($pid) {
    switch (post_action()) {
        case 'add':
            if (trim($_POST['name'] ?? '') !== '') {
                add_guest($db, $pid, $_POST);
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
                        'type' => $tmap[trim($r[2] ?? '')] ?? 'trainee', 'room_requested' => $r[3] ?? '', 'note' => $r[4] ?? '', 'id_card' => $r[5] ?? '']);
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
                $slot = slot_lookup($db, $key, $gid);
                if (!$slot) {
                    flash('error', 'ห้องนี้ไม่สามารถจัดได้');
                    break;
                }
                if ($slot['used'] >= $slot['cap']) {
                    flash('error', "ห้อง {$slot['label']} เต็มแล้ว ({$slot['used']}/{$slot['cap']} เตียง)");
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
        case 'delete':
            $db->prepare('DELETE FROM guests WHERE id=? AND project_id=?')->execute([(int)$_POST['id'], $pid]);
            audit('ลบผู้เข้าพัก #' . (int)$_POST['id']);
            break;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        redirect('guests.php?project=' . $pid);
    }
}

$projects = $db->query("SELECT id,name,start_date FROM projects WHERE status IN ('pending','checking','approved') ORDER BY start_date DESC")->fetchAll();
$proj = null;
$guests = [];
if ($pid) {
    $st = $db->prepare('SELECT * FROM projects WHERE id=?');
    $st->execute([$pid]);
    $proj = $st->fetch() ?: null;
    $st = $db->prepare('SELECT g.*, r.room_no, b.code bcode FROM guests g LEFT JOIN rooms r ON r.id=g.room_id LEFT JOIN buildings b ON b.id=r.building_id WHERE project_id=? ORDER BY g.id');
    $st->execute([$pid]);
    $guests = $st->fetchAll();
}
$slots = slot_list($db);

app_start('จัดผู้เข้าพักและเลขห้อง', $me, 'guests');
?>
<form method="get" class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><label style="margin:0">โครงการ</label>
  <select name="project" onchange="this.form.submit()" style="max-width:520px"><option value="">— เลือกโครงการ —</option>
  <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= $p['id'] == $pid ? 'selected' : '' ?>><?= e($p['name'] . ' (' . thai_date($p['start_date']) . ')') ?></option><?php endforeach; ?></select></form>
<?php if ($proj): ?>
<div class="card"><b><?= e($proj['name']) ?></b> <?= badge(PROJECT_STATUS, $proj['status']) ?>
  <div style="font-size:12.5px;color:#8A8F98;margin-top:4px"><?= e(thai_date($proj['start_date'])) ?> – <?= e(thai_date($proj['end_date'])) ?> · แจ้งไว้ ชาย <?= $proj['male'] ?> หญิง <?= $proj['female'] ?> · ลงรายชื่อแล้ว <?= count($guests) ?> คน</div>
  <a class="btn ghost" style="margin-top:10px" href="<?= e(url('reports.php?r=guests&export=1&project=' . $pid)) ?>">ดาวน์โหลดรายชื่อ (Excel CSV)</a></div>
<div class="row">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="project" value="<?= $pid ?>"><h2 style="margin-top:0">เพิ่มผู้เข้าพัก</h2>
  <div class="row"><div><label>ชื่อ–สกุล</label><input type="text" name="name" required></div>
  <div><label>เพศ</label><select name="gender"><option value="M">ชาย</option><option value="F">หญิง</option></select></div>
  <div><label>ประเภท</label><select name="type"><?php foreach (GUEST_TYPE as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div><label>ห้องที่ระบุ</label><input type="text" name="room_requested"></div>
  <div><label>เลขบัตรประชาชน (ถ้ามี)</label><input type="text" name="id_card" maxlength="13"></div></div>
  <label>หมายเหตุ/เงื่อนไข</label><input type="text" name="note"><p><button class="btn">เพิ่ม</button></p></form>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="project" value="<?= $pid ?>"><h2 style="margin-top:0">นำเข้ารายชื่อ (CSV/Excel)</h2>
  <p style="font-size:12.5px;color:#5B5450">ใน Excel เลือก บันทึกเป็น → CSV UTF-8 คอลัมน์: <code>ชื่อ-สกุล, เพศ(ช/ญ), ประเภท, ห้องที่ระบุ, หมายเหตุ, เลขบัตรประชาชน</code></p>
  <input type="file" name="csv" accept=".csv" required><p><button class="btn">นำเข้า</button></p></form></div>
<div class="card" style="padding:0;overflow:hidden"><table><tr><th>ชื่อ–สกุล</th><th>เพศ</th><th>ประเภท</th><th>ห้องที่ระบุ</th><th>ห้องที่จัดจริง</th><th>หมายเหตุ</th><th></th></tr>
<?php foreach ($guests as $g): ?><tr><td><b><?= e($g['name']) ?></b></td><td><?= $g['gender'] === 'M' ? 'ชาย' : 'หญิง' ?></td><td><?= e(GUEST_TYPE[$g['type']]) ?></td><td><?= e($g['room_requested']) ?></td>
<td><form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="project" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= $g['id'] ?>">
  <select name="slot" onchange="this.form.submit()" style="min-width:150px"><option value="">— ยังไม่จัด —</option>
  <?php $cur = $g['unit_id'] ? 'u' . $g['unit_id'] : ($g['room_id'] ? 'r' . $g['room_id'] : ''); foreach ($slots as $sl): $full = $sl['used'] >= $sl['cap'] && $sl['key'] !== $cur; ?><option value="<?= $sl['key'] ?>" <?= $sl['key'] === $cur ? 'selected' : '' ?> <?= $full ? 'disabled' : '' ?>><?= e($sl['label'] . " ({$sl['used']}/{$sl['cap']})") ?></option><?php endforeach; ?></select></form></td>
<td style="font-size:12px;color:#8A8F98"><?= e($g['note']) ?></td>
<td><form method="post" data-confirm="ลบรายการนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="project" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= $g['id'] ?>"><button class="btn ghost">ลบ</button></form></td></tr>
<?php endforeach; if (!$guests): ?><tr><td colspan="7">ยังไม่มีรายชื่อ</td></tr><?php endif; ?></table></div>
<?php endif; ?>
<?php app_end();
