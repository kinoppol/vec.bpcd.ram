<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';
require __DIR__ . '/views/building_map.php';

$user = Auth::require();
$db = Db::pdo();
$count = fn(string $sql): int => (int)$db->query($sql)->fetchColumn();

$totalRooms = $count("SELECT COUNT(*) FROM rooms WHERE type='lodging'");
$occupiedRooms = $count("SELECT COUNT(*) FROM rooms WHERE type='lodging' AND status IN ('reserved','occupied')");
$inHouse = $count("SELECT COUNT(*) FROM guests WHERE status='checked_in'");
$pending = $count("SELECT COUNT(*) FROM projects WHERE status IN ('pending','checking')");

$byBuilding = $db->query("SELECT b.name, COUNT(r.id) total, SUM(r.status IN ('reserved','occupied')) used
    FROM buildings b LEFT JOIN rooms r ON r.building_id=b.id AND r.type='lodging' GROUP BY b.id ORDER BY b.code")->fetchAll();
$monthly = $db->query("SELECT DATE_FORMAT(p.start_date,'%Y-%m') ym, COUNT(g.id) n FROM projects p LEFT JOIN guests g ON g.project_id=p.id
    WHERE p.status='approved' AND p.start_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym ORDER BY ym")->fetchAll();
$today = $db->query("SELECT p.name, p.status, (SELECT COUNT(*) FROM guests g WHERE g.project_id=p.id) guests
    FROM projects p WHERE p.status='approved' AND CURDATE() BETWEEN p.start_date AND p.end_date ORDER BY p.name")->fetchAll();
$maxM = max(1, ...array_map(fn($m) => (int)$m['n'], $monthly ?: [['n' => 1]]));

app_start('ภาพรวมระบบ', $user, 'home');
?>
<div class="kpis">
  <div class="card kpi"><small>ห้องพักทั้งหมด</small><div><?= $totalRooms ?></div></div>
  <div class="card kpi"><small>ห้องที่ถูกจอง/มีผู้พัก</small><div><?= $occupiedRooms ?></div></div>
  <div class="card kpi"><small>ผู้เข้าพักขณะนี้</small><div><?= $inHouse ?></div></div>
  <div class="card kpi"><small>คำขอรอตรวจสอบ</small><div><?= $pending ?></div></div>
</div>
<?php building_map($db, in_array($user['role'], ['admin', 'caretaker'], true)); ?>
<div class="row" style="margin-bottom:16px">
  <div class="card"><b>จำนวนผู้เข้าพักรายเดือน</b>
    <div style="display:flex;align-items:flex-end;gap:10px;height:150px;margin-top:16px">
    <?php foreach ($monthly as $m): ?><div style="flex:1;text-align:center;font-size:11px;color:#8A8F98"><?= $m['n'] ?><div style="background:#7A1E2C;border-radius:6px 6px 0 0;height:<?= max(3, round(110 * $m['n'] / $maxM)) ?>px"></div><?= e($m['ym']) ?></div><?php endforeach; ?>
    <?php if (!$monthly): ?><span style="color:#8A8F98;font-size:13px">ยังไม่มีข้อมูล</span><?php endif; ?></div></div>
  <div class="card"><b>อัตราการใช้งานตามอาคาร</b>
    <?php foreach ($byBuilding as $b): $pct = $b['total'] ? round(100 * $b['used'] / $b['total']) : 0; ?>
    <div style="margin-top:14px"><div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px"><span><?= e($b['name']) ?></span><span style="color:#8A8F98"><?= $pct ?>% (<?= (int)$b['used'] ?>/<?= $b['total'] ?>)</span></div>
    <div style="height:8px;background:#F0EAE6;border-radius:5px;overflow:hidden"><div style="height:100%;background:#7A1E2C;width:<?= $pct ?>%"></div></div></div>
    <?php endforeach; if (!$byBuilding): ?><p style="color:#8A8F98;font-size:13px">ยังไม่มีอาคาร</p><?php endif; ?></div>
</div>
<div class="card" style="padding:0;overflow:hidden"><table><tr><th>โครงการที่เข้าพักวันนี้</th><th>ผู้เข้าพัก</th><th>สถานะ</th></tr>
<?php foreach ($today as $t): ?><tr><td><b><?= e($t['name']) ?></b></td><td><?= $t['guests'] ?> คน</td><td><?= badge(PROJECT_STATUS, $t['status']) ?></td></tr>
<?php endforeach; if (!$today): ?><tr><td colspan="3">ไม่มีโครงการที่เข้าพักวันนี้</td></tr><?php endif; ?></table></div>
<?php app_end();
