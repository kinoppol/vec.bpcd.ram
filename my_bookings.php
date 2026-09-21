<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('owner');
$db = Db::pdo();

if (post_action() === 'cancel') {
    $st = $db->prepare("UPDATE projects SET status='cancelled' WHERE id=? AND owner_id=? AND status IN ('pending','checking','approved')");
    $st->execute([(int)$_POST['id'], $me['id']]);
    if ($st->rowCount()) {
        audit('ยกเลิกคำขอจอง #' . (int)$_POST['id']);
        flash('success', 'ยกเลิกคำขอแล้ว');
        notify('คำขอจองถูกยกเลิก #' . (int)$_POST['id'], $me['full_name'] . ' ยกเลิกคำขอจอง', caretaker_emails(), true);
    }
    redirect('my_bookings.php');
}

$st = $db->prepare('SELECT p.*, r.room_no FROM projects p LEFT JOIN rooms r ON r.id=p.meeting_room_id WHERE owner_id=? ORDER BY p.id DESC');
$st->execute([$me['id']]);
$rows = $st->fetchAll();
$steps = ['ยื่นคำขอ', 'ตรวจสอบความพร้อม', 'ผลการพิจารณา'];
app_start('สถานะการจอง', $me, 'my');
?>
<?php foreach ($rows as $p):
    $cur = ['pending' => 0, 'checking' => 1, 'approved' => 2, 'rejected' => 2, 'cancelled' => 2][$p['status']];
    $conf = project_conflicts($p); ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;gap:12px"><div><b><?= e($p['name']) ?></b>
    <div style="font-size:12.5px;color:#5B5450;margin-top:4px">เลขที่ <?= e($p['booking_no']) ?> · <?= e(thai_date($p['start_date'])) ?> – <?= e(thai_date($p['end_date'])) ?><?= $p['room_no'] ? ' · ห้องประชุม ' . e($p['room_no']) : '' ?> · ชาย <?= $p['male'] ?> หญิง <?= $p['female'] ?></div></div>
    <?= badge(PROJECT_STATUS, $p['status']) ?></div>
  <div style="display:flex;gap:8px;margin:14px 0;font-size:12px"><?php foreach ($steps as $i => $s): ?>
    <span class="badge <?= $i <= $cur ? ($i === $cur && $p['status'] !== 'approved' && $i === 2 ? 'bad' : 'ok') : 'pend' ?>"><?= $i + 1 ?>. <?= e($i === 2 ? (PROJECT_STATUS[$p['status']][0] === 'อนุมัติแล้ว' ? 'อนุมัติแล้ว' : 'ผลการพิจารณา') : $s) ?></span><?php endforeach; ?></div>
  <?php if ($p['status_note']): ?><div style="font-size:12.5px;color:#5B5450">หมายเหตุจากผู้ดูแล: <?= e($p['status_note']) ?></div><?php endif; ?>
  <?php if ($conf && in_array($p['status'], ['pending', 'checking'], true)): ?><div class="alert alert-error" style="margin-top:10px">⚠ ทับซ้อนกับ "<?= e($conf[0]['name']) ?>" — รอผู้ดูแลตรวจสอบและแจ้งผล</div><?php endif; ?>
  <?php if (in_array($p['status'], ['pending', 'checking', 'approved'], true)): ?>
  <?php if ($p['status'] === 'pending'): ?><a class="btn ghost" style="margin-top:10px" href="<?= e(url('book.php?id=' . $p['id'])) ?>">แก้ไขคำขอ</a><?php endif; ?>
  <form method="post" style="margin-top:10px;display:inline-block" data-confirm="ยืนยันยกเลิกคำขอนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn ghost">ยกเลิกคำขอ</button></form><?php endif; ?>
</div>
<?php endforeach; if (!$rows): ?><div class="card">ยังไม่มีรายการจอง — <a href="<?= e(url('book.php')) ?>">จองห้องพัก</a></div><?php endif; ?>
<?php app_end();
