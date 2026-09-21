<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('caretaker');
$db = Db::pdo();

if ($act = post_action()) {
    $id = (int)$_POST['id'];
    $st = $db->prepare('SELECT * FROM guests WHERE id=?');
    $st->execute([$id]);
    $g = $st->fetch();
    if ($g) {
        $roomLeft = fn() => (int)$db->query('SELECT COUNT(*) FROM guests WHERE status=\'checked_in\' AND room_id=' . (int)$g['room_id'])->fetchColumn();
        if ($act === 'in' && $g['status'] === 'expected') {
            if (!$g['room_id']) {
                flash('error', 'ยังไม่ได้จัดห้องให้ผู้เข้าพักรายนี้');
            } else {
                $db->prepare("UPDATE guests SET status='checked_in', key_status='issued', checkin_at=NOW() WHERE id=?")->execute([$id]);
                $db->prepare("UPDATE rooms SET status='occupied' WHERE id=?")->execute([$g['room_id']]);
                audit("Check-in {$g['name']}");
            }
        } elseif ($act === 'out' && $g['status'] === 'checked_in') {
            $db->prepare("UPDATE guests SET status='checked_out', key_status='returned', checkout_at=NOW() WHERE id=?")->execute([$id]);
            if ($roomLeft() === 0) {
                $db->prepare("UPDATE rooms SET status='cleaning' WHERE id=?")->execute([$g['room_id']]);
            }
            audit("Check-out {$g['name']}");
        } elseif ($act === 'noshow' && $g['status'] === 'expected') {
            $db->prepare("UPDATE guests SET status='no_show' WHERE id=?")->execute([$id]);
            audit("No-show {$g['name']}");
        }
    }
    redirect('checkin.php?q=' . urlencode($_POST['q'] ?? ''));
}

$q = trim($_GET['q'] ?? '');
$sql = "SELECT g.*, p.name pname, p.booking_no, r.room_no, b.code bcode, un.label unit FROM guests g JOIN projects p ON p.id=g.project_id
    LEFT JOIN rooms r ON r.id=g.room_id LEFT JOIN room_units un ON un.id=g.unit_id LEFT JOIN buildings b ON b.id=r.building_id
    WHERE p.status='approved'";
$args = [];
if ($q !== '') {
    $sql .= ' AND (g.name LIKE ? OR p.booking_no LIKE ? OR g.id_card = ?)';
    $args = ["%$q%", "%$q%", $q];
} else {
    $sql .= ' AND CURDATE() BETWEEN p.start_date AND p.end_date';
}
$st = $db->prepare($sql . ' ORDER BY p.id, g.name LIMIT 200');
$st->execute($args);
$rows = $st->fetchAll();

app_start('Check-in / Check-out', $me, 'checkin');
?>
<form class="card" style="display:flex;gap:10px"><input type="text" name="q" value="<?= e($q) ?>" placeholder="ค้นหาด้วยชื่อ / เลขที่จอง / เลขบัตรประชาชน" autofocus><button class="btn">ค้นหา</button></form>
<div class="card" style="font-size:12.5px;color:#5B5450"><?= $q === '' ? 'แสดงผู้เข้าพักของโครงการที่อนุมัติและกำลังดำเนินอยู่วันนี้ (เฉพาะโครงการสถานะ "อนุมัติแล้ว")' : 'ผลการค้นหา ' . count($rows) . ' รายการ' ?></div>
<div class="card" style="padding:0;overflow:hidden"><table class="stack"><tr><th>ผู้เข้าพัก</th><th>โครงการ</th><th>ห้อง</th><th>กุญแจ</th><th>สถานะ</th><th></th></tr>
<?php foreach ($rows as $g): ?><tr><td><b><?= e($g['name']) ?></b></td><td style="font-size:12px"><?= e($g['pname']) ?><br><span style="color:#8A8F98"><?= e($g['booking_no']) ?></span></td>
<td><?= $g['room_no'] ? e(room_label($g['bcode'], $g['room_no'], $g['unit'])) : '<span style="color:#A32638">ยังไม่จัด</span>' ?></td>
<td><?= ['none' => 'ยังไม่รับ', 'issued' => 'รับแล้ว', 'returned' => 'คืนแล้ว'][$g['key_status']] ?></td><td><?= badge(GUEST_STATUS, $g['status']) ?></td>
<td><form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $g['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
<?php if ($g['status'] === 'expected'): ?><button class="btn" name="action" value="in">Check-in</button><button class="btn ghost" name="action" value="noshow">No-show</button>
<?php elseif ($g['status'] === 'checked_in'): ?><button class="btn ghost" name="action" value="out">Check-out</button><?php endif; ?></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6">ไม่พบข้อมูล</td></tr><?php endif; ?></table></div>
<?php app_end();
