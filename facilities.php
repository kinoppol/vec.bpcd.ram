<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

// นำเข้า/ส่งออกข้อมูลอาคาร ห้องพัก ห้องประชุม ห้องย่อย ประเภทห้อง (พร้อมรูป) เป็นไฟล์ ZIP
$me = Auth::require('caretaker');
$db = Db::pdo();

if (isset($_GET['export'])) {
    try {
        $file = facilities_export_zip($db);
        audit('ส่งออกข้อมูลอาคารและห้อง (ZIP)');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="facilities_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        @unlink($file);
        exit;
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
        redirect('facilities.php');
    }
}

try {
    if (post_action() === 'import') {
        if (!$tmp = uploaded('zip')) {
            throw new RuntimeException('กรุณาเลือกไฟล์ ZIP');
        }
        $n = facilities_import_zip($db, $tmp);
        audit("นำเข้าข้อมูลอาคารและห้อง (ZIP): อาคาร {$n['buildings']} ห้อง {$n['rooms']} ประเภทห้อง {$n['types']}");
        flash('success', "นำเข้าสำเร็จ: อาคาร {$n['buildings']} · ห้อง {$n['rooms']} · ห้องย่อย {$n['units']} · ประเภทห้อง {$n['types']} · รูป {$n['images']}");
        redirect('facilities.php');
    }
} catch (Throwable $ex) {
    flash('error', $ex->getMessage());
    redirect('facilities.php');
}

$c = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
$sum = [
    'อาคาร' => $c('SELECT COUNT(*) FROM buildings'),
    'ห้องพัก' => $c("SELECT COUNT(*) FROM rooms WHERE type='lodging'"),
    'ห้องประชุม' => $c("SELECT COUNT(*) FROM rooms WHERE type='meeting'"),
    'ห้องย่อย' => $c('SELECT COUNT(*) FROM room_units'),
    'ประเภทห้อง' => $c('SELECT COUNT(*) FROM room_types'),
];
app_start('นำเข้า / ส่งออกข้อมูลอาคารและห้อง', $me, 'facilities');
?>
<div class="card">
  <h3 style="margin-top:0">⬇ ส่งออกข้อมูล (ZIP)</h3>
  <p style="color:#5B5450;font-size:13px">ไฟล์ ZIP ประกอบด้วย <code>data.json</code> (อาคาร ห้องพัก ห้องประชุม ห้องย่อย ประเภทห้อง และสถานะห้อง) และโฟลเดอร์ <code>images/</code> (รูปห้องตัวอย่าง) — ใช้สำรองข้อมูลหรือย้ายไปอีกเครื่องได้</p>
  <div class="actions" style="margin:10px 0 14px">
    <?php foreach ($sum as $k => $v): ?><span class="badge" style="background:#F7F3EE;color:#5B5450"><?= e($k) ?> <?= $v ?></span><?php endforeach; ?>
  </div>
  <a class="btn" href="<?= e(url('facilities.php?export=1')) ?>">ดาวน์โหลดไฟล์ ZIP</a>
</div>
<div class="card">
  <h3 style="margin-top:0">⬆ นำเข้าข้อมูล (ZIP)</h3>
  <ul style="font-size:13px;color:#5B5450;padding-left:18px">
    <li>อาคารจับคู่ด้วย<b>รหัสอาคาร</b> ห้องจับคู่ด้วย<b>อาคาร + เลขห้อง</b> ประเภทห้องจับคู่ด้วย<b>ชื่อ</b></li>
    <li>ข้อมูลที่มีอยู่แล้วจะถูกอัปเดต ที่ยังไม่มีจะถูกเพิ่ม — ไม่ลบข้อมูลเดิม</li>
    <li>สถานะห้องที่มีอยู่แล้วคงเดิม (ใช้สถานะจากไฟล์กับห้องใหม่เท่านั้น)</li>
    <li>ถ้าเกิดข้อผิดพลาด จะไม่บันทึกข้อมูลใดเลย</li>
  </ul>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="import">
    <input type="file" name="zip" accept=".zip,application/zip" required style="flex:1 1 260px">
    <button class="btn" data-confirm="นำเข้าข้อมูลจากไฟล์นี้? ข้อมูลที่ตรงกันจะถูกอัปเดต">นำเข้า</button>
  </form>
</div>
<?php app_end();
