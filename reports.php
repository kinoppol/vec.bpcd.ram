<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require(['caretaker', 'exec']);
$db = Db::pdo();

$reports = ['rooms' => 'ห้องว่าง/เต็ม/ปิดใช้งาน', 'guests' => 'รายชื่อผู้เข้าพัก', 'monthly' => 'สถิติรายเดือน/โครงการ'];
$r = isset($reports[$_GET['r'] ?? '']) ? $_GET['r'] : 'rooms';
$pid = (int)($_GET['project'] ?? 0);

// ดึงข้อมูลเป็น [หัวตาราง, แถว] ใช้ร่วมกันทั้งหน้าจอและ CSV
function report_data(PDO $db, string $r, int $pid): array
{
    if ($r === 'rooms') {
        $rows = $db->query("SELECT b.name,r.room_no,r.floor,r.beds,COALESCE((SELECT GROUP_CONCAT(u.label ORDER BY u.label SEPARATOR ',') FROM room_units u WHERE u.room_id=r.id),''),IF(r.type='meeting','ห้องประชุม','ห้องพัก'),r.status,COALESCE(r.status_note,''),
            (SELECT COUNT(*) FROM guests g WHERE g.room_id=r.id AND g.status IN ('expected','checked_in')) FROM rooms r JOIN buildings b ON b.id=r.building_id ORDER BY b.code,r.floor,r.room_no")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as &$x) {
            $x[6] = ROOM_STATUS[$x[6]][0];
        }
        return [['อาคาร', 'ห้อง', 'ชั้น', 'เตียง', 'ห้องย่อย', 'ประเภท', 'สถานะ', 'สาเหตุ (ไม่ว่าง/ปิดซ่อม)', 'ผู้เข้าพักที่จัดไว้'], $rows];
    }
    if ($r === 'guests') {
        $sql = "SELECT p.name,g.name,IF(g.gender='M','ชาย','หญิง'),g.type,COALESCE(CONCAT(b.code,'-',rm.room_no,IF(un.label IS NULL,'',CONCAT('/',un.label))),''),g.status,g.checkin_at,g.checkout_at
            FROM guests g JOIN projects p ON p.id=g.project_id LEFT JOIN rooms rm ON rm.id=g.room_id LEFT JOIN room_units un ON un.id=g.unit_id LEFT JOIN buildings b ON b.id=rm.building_id" . ($pid ? ' WHERE p.id=?' : '') . ' ORDER BY p.id,b.code,rm.room_no,g.name';
        $st = $db->prepare($sql);
        $st->execute($pid ? [$pid] : []);
        $rows = $st->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as &$x) {
            $x[3] = GUEST_TYPE[$x[3]];
            $x[5] = GUEST_STATUS[$x[5]][0];
        }
        return [['โครงการ', 'ชื่อ–สกุล', 'เพศ', 'ประเภท', 'ห้อง', 'สถานะ', 'Check-in', 'Check-out'], $rows];
    }
    $rows = $db->query("SELECT DATE_FORMAT(p.start_date,'%Y-%m'),p.name,p.status,DATEDIFF(p.end_date,p.start_date)+1,COUNT(g.id),SUM(g.status IN ('checked_in','checked_out'))
        FROM projects p LEFT JOIN guests g ON g.project_id=p.id WHERE p.status<>'cancelled' GROUP BY p.id ORDER BY p.start_date DESC")->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as &$x) {
        $x[2] = PROJECT_STATUS[$x[2]][0];
        $x[5] = (int)$x[5];
    }
    return [['เดือน', 'โครงการ', 'สถานะ', 'จำนวนวัน', 'ผู้เข้าพักตามรายชื่อ', 'เข้าพักจริง'], $rows];
}

[$head, $rows] = report_data($db, $r, $pid);

if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"report_$r.csv\"");
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF"); // ให้ Excel อ่านภาษาไทยถูกต้อง
    fputcsv($o, $head);
    foreach ($rows as $row) {
        // กัน CSV/formula injection ใน Excel
        fputcsv($o, array_map(fn($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) ? "'" . $c : $c, $row));
    }
    exit;
}

$projects = $db->query('SELECT id,name FROM projects ORDER BY id DESC')->fetchAll();
app_start('รายงาน / Export', $me, 'reports');
?>
<style>@media print{.side,.top,.noprint,#ai{display:none!important}.app{display:block;height:auto}.content{overflow:visible}}</style>
<div class="actions noprint" style="margin-bottom:16px">
<?php foreach ($reports as $k => $l): ?><a class="btn <?= $k === $r ? '' : 'ghost' ?>" href="?r=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?>
</div>
<div class="card noprint" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <?php if ($r === 'guests'): ?><form method="get" style="display:flex;gap:8px"><input type="hidden" name="r" value="guests"><select name="project" onchange="this.form.submit()"><option value="0">ทุกโครงการ</option>
    <?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" <?= $p['id'] == $pid ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></form><?php endif; ?>
  <a class="btn" href="?<?= e(http_build_query(['r' => $r, 'project' => $pid, 'export' => 1])) ?>">ดาวน์โหลด Excel (CSV)</a>
  <button class="btn ghost" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
</div>
<h2 style="margin-top:0"><?= e($reports[$r]) ?> <span style="font-weight:400;font-size:12px;color:#8A8F98">ณ <?= e(thai_date(date('Y-m-d'))) ?> <?= date('H:i') ?></span></h2>
<div class="card" style="padding:0;overflow:auto"><table><tr><?php foreach ($head as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
<?php foreach ($rows as $row): ?><tr><?php foreach ($row as $c): ?><td><?= e((string)$c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= count($head) ?>">ไม่มีข้อมูล</td></tr><?php endif; ?></table></div>
<?php app_end();
