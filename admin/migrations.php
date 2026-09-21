<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/views/layout.php';

$user = Auth::require('admin');
$migrator = new Migrator(Db::pdo());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        switch ($_POST['action'] ?? '') {
            case 'migrate':
                $ran = $migrator->migrate();
                audit('รัน migrations ' . count($ran) . ' รายการ');
                flash('success', $ran ? 'รันสำเร็จ ' . count($ran) . ' รายการ' : 'ไม่มีรายการที่รอดำเนินการ');
                break;
            case 'migrate_to':
                $ran = $migrator->migrate((string)($_POST['name'] ?? ''));
                audit('รัน migrations ถึง ' . ($_POST['name'] ?? '') . ' (' . count($ran) . ' รายการ)');
                flash('success', 'รันสำเร็จ ' . count($ran) . ' รายการ (เรียงตามลำดับจนถึงรายการที่เลือก)');
                break;
            case 'rollback':
                $done = $migrator->rollback();
                audit('ย้อนกลับ migrations batch ล่าสุด ' . count($done) . ' รายการ');
                flash($done ? 'success' : 'warn', $done ? 'ย้อนกลับ ' . count($done) . ' รายการ (batch ล่าสุด)' : 'ไม่มีรายการให้ย้อนกลับ');
                break;
            case 'rollback_one':
                $done = $migrator->rollbackOne();
                audit('ย้อนกลับ migration ' . ($done[0] ?? '-'));
                flash($done ? 'success' : 'warn', $done ? 'ย้อนกลับ ' . $done[0] : 'ไม่มีรายการให้ย้อนกลับ');
                break;
        }
    } catch (Throwable $ex) {
        flash('error', 'เกิดข้อผิดพลาด: ' . $ex->getMessage());
    }
    redirect('admin/migrations.php');
}

$rows = $migrator->status();
$pending = count(array_filter($rows, fn($r) => !$r['applied']));
$lastApplied = null;
foreach ($rows as $r) {
    if ($r['applied']) {
        $lastApplied = $r['name'];
    }
}

app_start('จัดการฐานข้อมูล (Migrations)', $user, 'migrations');
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>ทั้งหมด <b><?= count($rows) ?></b> · ใช้แล้ว <b><?= count($rows) - $pending ?></b> · รอดำเนินการ <b style="color:<?= $pending ? '#A32638' : 'inherit' ?>"><?= $pending ?></b>
      <div style="font-size:12px;color:#8A8F98;margin-top:4px">เพิ่มไฟล์ใหม่ในโฟลเดอร์ <code>migrations/</code> แล้วกดรัน — <b>แนะนำให้สำรองฐานข้อมูลก่อนทุกครั้ง</b> (DDL ของ MariaDB ยกเลิกกลางคันไม่ได้)</div></div>
    <form method="post" class="actions"><?= csrf_field() ?>
      <button class="btn" name="action" value="migrate" <?= $pending ? '' : 'disabled' ?>>รัน Migrations ที่รอทั้งหมด</button>
      <button class="btn ghost" name="action" value="rollback_one" <?= $lastApplied ? '' : 'disabled' ?> data-confirm="ย้อนกลับ migration ล่าสุด 1 รายการ? ข้อมูลในตารางที่ถูกลบจะหายถาวร">ย้อนกลับ 1 รายการล่าสุด</button>
      <button class="btn ghost" name="action" value="rollback" data-confirm="ย้อนกลับทั้ง batch ล่าสุด? ข้อมูลในตารางที่ถูกลบจะหายถาวร">ย้อนกลับ batch ล่าสุด</button>
    </form>
  </div>
</div>
<div class="card" style="padding:0;overflow:auto">
<table><tr><th>ไฟล์</th><th>รายละเอียด</th><th>Batch</th><th>วันที่ใช้</th><th>สถานะ</th><th></th></tr>
<?php foreach ($rows as $r): ?>
  <tr><td><code><?= e($r['name']) ?></code></td><td><?= e($r['title']) ?><?= $migrator->isProtected($r['name']) ? ' <span class="badge" style="background:#F7F3EE;color:#5B5450" title="ย้อนกลับจากเมนูนี้ไม่ได้">ระบบหลัก</span>' : '' ?></td>
  <td><?= e((string)$r['batch']) ?></td><td><?= e($r['applied_at']) ?></td>
  <td><span class="badge <?= $r['applied'] ? 'ok' : 'pend' ?>"><?= $r['applied'] ? 'ใช้แล้ว' : 'รอดำเนินการ' ?></span></td>
  <td><?php if (!$r['applied']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($r['name']) ?>"><button class="btn ghost" name="action" value="migrate_to" style="padding:6px 12px" title="รันตามลำดับจนถึงรายการนี้">รันถึงรายการนี้</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6">ไม่พบไฟล์ migration</td></tr><?php endif; ?>
</table></div>
<?php app_end();
