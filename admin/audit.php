<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/views/layout.php';

$me = Auth::require('admin');
$rows = Db::pdo()->query('SELECT a.created_at,a.action,u.full_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 300')->fetchAll();
app_start('บันทึกการใช้งาน (Audit Log)', $me, 'audit');
?>
<div class="card" style="padding:0;overflow:hidden"><table><tr><th>วันที่/เวลา</th><th>ผู้ใช้งาน</th><th>การดำเนินการ</th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['full_name'] ?? '-') ?></td><td><?= e($r['action']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3">ยังไม่มีข้อมูล (แสดง 300 รายการล่าสุด)</td></tr><?php endif; ?></table></div>
<?php app_end();
