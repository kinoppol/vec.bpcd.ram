<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('caretaker');
$db = Db::pdo();

if (post_action() === 'set') {
    $to = $_POST['status'] ?? '';
    if (isset(PROJECT_STATUS[$to]) && $to !== 'pending') {
        $db->prepare('UPDATE projects SET status=?, status_note=? WHERE id=?')->execute([$to, trim($_POST['status_note'] ?? '') ?: null, (int)$_POST['id']]);
        audit('เปลี่ยนสถานะคำขอ #' . (int)$_POST['id'] . ' เป็น ' . $to);
        flash('success', 'อัปเดตสถานะแล้ว');
        $q = $db->prepare('SELECT p.name,p.booking_no,u.email FROM projects p JOIN users u ON u.id=p.owner_id WHERE p.id=?');
        $q->execute([(int)$_POST['id']]);
        if ($pr = $q->fetch()) {
            notify("ผลการพิจารณาคำขอจอง {$pr['booking_no']}", "โครงการ {$pr['name']}: " . PROJECT_STATUS[$to][0] . (trim($_POST['status_note'] ?? '') !== '' ? ' — ' . trim($_POST['status_note']) : ''), [$pr['email']]);
        }
    }
    redirect('requests.php?f=' . urlencode($_POST['f'] ?? 'all'));
}

$f = $_GET['f'] ?? 'all';
$sql = 'SELECT p.*, u.full_name owner, r.room_no FROM projects p JOIN users u ON u.id=p.owner_id LEFT JOIN rooms r ON r.id=p.meeting_room_id';
$args = [];
if (isset(PROJECT_STATUS[$f])) {
    $sql .= ' WHERE p.status=?';
    $args[] = $f;
}
$st = $db->prepare($sql . ' ORDER BY p.start_date DESC, p.id DESC');
$st->execute($args);
$rows = $st->fetchAll();

app_start('คำขอจองห้องพักและห้องประชุม', $me, 'requests');
?>
<div class="actions" style="margin-bottom:16px"><a class="btn <?= $f === 'all' ? '' : 'ghost' ?>" href="?f=all">ทั้งหมด</a>
<?php foreach (PROJECT_STATUS as $k => [$l]): ?><a class="btn <?= $f === $k ? '' : 'ghost' ?>" href="?f=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></div>
<?php foreach ($rows as $p): $conf = in_array($p['status'], ['pending', 'checking'], true) ? project_conflicts($p) : []; ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;gap:12px"><div><b><?= e($p['name']) ?></b>
    <div style="font-size:12.5px;color:#5B5450;margin-top:4px"><?= e($p['booking_no']) ?> · <?= e(thai_date($p['start_date'])) ?> – <?= e(thai_date($p['end_date'])) ?><?= $p['room_no'] ? ' · ห้องประชุม ' . e($p['room_no']) : '' ?> · ผู้เข้าพัก <?= $p['male'] + $p['female'] ?> คน (ห้อง: อบรม <?= $p['rooms_trainee'] ?> / วิทยากร <?= $p['rooms_speaker'] ?> / กรรมการ <?= $p['rooms_committee'] ?>)</div>
    <div style="font-size:12px;color:#8A8F98;margin-top:2px">ยื่นโดย <?= e($p['owner']) ?><?= $p['note'] ? ' · ' . e($p['note']) : '' ?></div></div><?= badge(PROJECT_STATUS, $p['status']) ?></div>
  <?php foreach ($conf as $c): ?><div class="alert alert-error" style="margin-top:10px">⚠ ทับซ้อนกับการจองของ "<?= e($c['name']) ?>" ในห้องประชุมและช่วงเวลาเดียวกัน</div><?php endforeach; ?>
  <form method="post" class="actions" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>">
    <input type="text" name="status_note" placeholder="หมายเหตุถึงเจ้าของโครงการ" style="max-width:280px" value="<?= e($p['status_note']) ?>">
    <button class="btn ghost" name="status" value="checking">ตรวจสอบความพร้อม</button><button class="btn" name="status" value="approved">อนุมัติ</button><button class="btn ghost" name="status" value="rejected">ไม่อนุมัติ</button>
    <a class="btn ghost" href="<?= e(url('guests.php?project=' . $p['id'])) ?>">รายชื่อผู้เข้าพัก</a></form>
</div>
<?php endforeach; if (!$rows): ?><div class="card">ไม่มีคำขอ</div><?php endif; ?>
<?php app_end();
