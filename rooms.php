<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('caretaker');
$db = Db::pdo();

// ส่งออกอาคาร/ห้องพัก/ห้องประชุม/ประเภทห้อง (พร้อมรูป) เป็นไฟล์ ZIP
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
        redirect('rooms.php');
    }
}

/** อ่านไฟล์ที่อัปโหลด คืนค่า path ชั่วคราว หรือ null ถ้าไม่ได้เลือกไฟล์ */
function uploaded(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์กำหนด)');
    }
    return $f['tmp_name'];
}

try {
    switch (post_action()) {
        case 'import':
            if (!$tmp = uploaded('zip')) {
                throw new RuntimeException('กรุณาเลือกไฟล์ ZIP');
            }
            $n = facilities_import_zip($db, $tmp);
            audit("นำเข้าข้อมูลอาคารและห้อง (ZIP): อาคาร {$n['buildings']} ห้อง {$n['rooms']} ประเภทห้อง {$n['types']}");
            flash('success', "นำเข้าสำเร็จ: อาคาร {$n['buildings']} · ห้อง {$n['rooms']} · ห้องย่อย {$n['units']} · ประเภทห้อง {$n['types']} · รูป {$n['images']}");
            break;
        case 'rtype_save':
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
            if ($name === '') {
                throw new RuntimeException('กรุณาระบุชื่อประเภทห้อง');
            }
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
            if ($tid = (int)($_POST['id'] ?? 0)) {
                $db->prepare('UPDATE room_types SET name=?, note=? WHERE id=?')->execute([$name, $note, $tid]);
            } else {
                $db->prepare('INSERT INTO room_types (name,note) VALUES (?,?)')->execute([$name, $note]);
                $tid = (int)$db->lastInsertId();
            }
            if ($tmp = uploaded('image')) {
                room_type_save_image($db, $tid, (string)file_get_contents($tmp));
            }
            audit('บันทึกประเภทห้อง ' . $name);
            break;
        case 'rtype_del':
            $st = $db->prepare('SELECT image FROM room_types WHERE id=?');
            $st->execute([(int)$_POST['id']]);
            $img = room_type_image_path((string)$st->fetchColumn());
            $db->prepare('DELETE FROM room_types WHERE id=?')->execute([(int)$_POST['id']]);
            if ($img) {
                @unlink($img);
            }
            audit('ลบประเภทห้อง #' . (int)$_POST['id']);
            break;
        case 'room_rtype':
            $tid = (int)($_POST['room_type_id'] ?? 0);
            $db->prepare('UPDATE rooms SET room_type_id=? WHERE id=?')->execute([$tid ?: null, (int)$_POST['id']]);
            audit('กำหนดประเภทห้อง #' . (int)$_POST['id']);
            break;
        case 'building':
            $db->prepare('INSERT INTO buildings (code,name,floors) VALUES (?,?,?)')
                ->execute([trim($_POST['code']), trim($_POST['name']), max(1, (int)$_POST['floors'])]);
            audit('เพิ่มอาคาร ' . trim($_POST['name']));
            break;
        case 'room':
            $db->prepare('INSERT INTO rooms (building_id,room_no,floor,beds,type,room_type_id) VALUES (?,?,?,?,?,?)')
                ->execute([(int)$_POST['building_id'], trim($_POST['room_no']), max(1, (int)$_POST['floor']), max(1, (int)$_POST['beds']),
                    $_POST['type'] === 'meeting' ? 'meeting' : 'lodging', (int)($_POST['room_type_id'] ?? 0) ?: null]);
            audit('เพิ่มห้อง ' . trim($_POST['room_no']));
            break;
        case 'unit_add':
            $label = trim($_POST['label'] ?? '');
            if ($label === '' || mb_strlen($label) > 20) {
                throw new RuntimeException('กรุณาระบุชื่อห้องย่อย (เช่น A, B)');
            }
            $db->prepare('INSERT INTO room_units (room_id,label,beds) VALUES (?,?,?)')->execute([(int)$_POST['id'], $label, max(1, (int)($_POST['ubeds'] ?? 2))]);
            room_sync_beds($db, (int)$_POST['id']);
            audit("เพิ่มห้องย่อย $label ในห้อง #" . (int)$_POST['id']);
            break;
        case 'unit_del':
            $u = $db->prepare('SELECT room_id FROM room_units WHERE id=?');
            $u->execute([(int)$_POST['unit']]);
            if ($rid = $u->fetchColumn()) {
                $g = $db->prepare("SELECT COUNT(*) FROM guests WHERE unit_id=? AND status IN ('expected','checked_in')");
                $g->execute([(int)$_POST['unit']]);
                if ($g->fetchColumn() > 0) {
                    throw new RuntimeException('ลบไม่ได้: ห้องย่อยนี้มีผู้เข้าพักที่จัดไว้หรือกำลังพัก');
                }
                $db->prepare('DELETE FROM room_units WHERE id=?')->execute([(int)$_POST['unit']]);
                room_sync_beds($db, (int)$rid);
                audit('ลบห้องย่อย #' . (int)$_POST['unit']);
            }
            break;
        case 'status':
            if (isset(ROOM_STATUS[$_POST['status'] ?? ''])) {
                $stt = $_POST['status'];
                $note = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
                if ($stt === 'unavailable' && $note === '') {
                    throw new RuntimeException('กรุณาระบุสาเหตุที่ห้องไม่ว่าง (เช่น ใช้ในภารกิจอื่น)');
                }
                // เหตุผลเก็บเฉพาะสถานะที่ต้องมีเหตุผล — เปลี่ยนเป็นสถานะอื่นแล้วล้างทิ้ง
                $db->prepare('UPDATE rooms SET status=?, status_note=? WHERE id=?')
                    ->execute([$stt, in_array($stt, ROOM_STATUS_WITH_NOTE, true) && $note !== '' ? $note : null, (int)$_POST['id']]);
                audit('เปลี่ยนสถานะห้อง #' . (int)$_POST['id'] . ' เป็น ' . $stt . ($note !== '' && in_array($stt, ROOM_STATUS_WITH_NOTE, true) ? " ({$note})" : ''));
            }
            break;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        redirect('rooms.php?b=' . (int)($_POST['b'] ?? 0));
    }
} catch (Throwable $ex) {
    flash('error', str_contains($ex->getMessage(), 'Duplicate') ? 'รหัส/เลขห้องนี้มีอยู่แล้ว' : $ex->getMessage());
}

$buildings = $db->query('SELECT * FROM buildings ORDER BY code')->fetchAll();
$bid = (int)($_GET['b'] ?? ($buildings[0]['id'] ?? 0));
if (!in_array($bid, array_map('intval', array_column($buildings, 'id')), true)) {
    $bid = (int)($buildings[0]['id'] ?? 0);
}

// ห้องทั้งหมด จัดกลุ่มตามอาคาร → ชั้น (โหลดครั้งเดียว ให้สลับแท็บได้ทันทีโดยไม่โหลดหน้าใหม่)
$byB = [];
$stat = [];
foreach ($db->query('SELECT * FROM rooms ORDER BY floor,room_no')->fetchAll() as $r) {
    $byB[$r['building_id']][$r['floor']][] = $r;
    $stat[$r['building_id']][$r['status']] = ($stat[$r['building_id']][$r['status']] ?? 0) + 1;
    $stat[$r['building_id']]['_all'] = ($stat[$r['building_id']]['_all'] ?? 0) + 1;
}
$units = [];
foreach ($db->query('SELECT * FROM room_units ORDER BY label')->fetchAll() as $u) {
    $units[$u['room_id']][] = $u;
}

$rtypes = [];
foreach ($db->query('SELECT t.*, (SELECT COUNT(*) FROM rooms r WHERE r.room_type_id=t.id) used FROM room_types t ORDER BY t.name')->fetchAll() as $t) {
    $t['src'] = room_type_image_path($t['image']) ? url('room_image.php?t=' . $t['id'] . '&v=' . rawurlencode((string)$t['image'])) : null;
    $rtypes[$t['id']] = $t;
}

$hues = [350, 205, 150, 32, 270, 175, 15, 230]; // โทนสีตามลำดับอาคาร (วนซ้ำเมื่อมีมากกว่า 8)
$hueOf = [];
foreach ($buildings as $i => $b) {
    $hueOf[$b['id']] = $hues[$i % count($hues)];
}
app_start('สถานะห้องพัก', $me, 'rooms');
?>
<div class="actions" style="margin-bottom:16px">
  <button type="button" class="btn" data-open="dlgBuilding">+ เพิ่มอาคาร</button>
  <button type="button" class="btn" data-open="dlgRoom" <?= $buildings ? '' : 'disabled title="เพิ่มอาคารก่อน"' ?>>+ เพิ่มห้อง</button>
  <button type="button" class="btn ghost" data-open="dlgTypes">ประเภทห้อง / รูปห้อง (<?= count($rtypes) ?>)</button>
  <span style="margin-left:auto;display:flex;gap:8px">
    <a class="btn ghost" href="<?= e(url('rooms.php?export=1')) ?>" title="อาคาร ห้องพัก ห้องประชุม ห้องย่อย ประเภทห้องและรูป">⬇ ส่งออก ZIP</a>
    <button type="button" class="btn ghost" data-open="dlgImport">⬆ นำเข้า ZIP</button>
  </span>
</div>

<?php if (!$buildings): ?>
  <div class="card" style="color:#8A8F98">ยังไม่มีอาคาร — กด "+ เพิ่มอาคาร" ด้านบน</div>
<?php else: ?>
<div class="tabs" role="tablist">
  <?php foreach ($buildings as $b): $s = $stat[$b['id']] ?? []; ?>
    <button type="button" class="tab <?= $b['id'] == $bid ? 'on' : '' ?>" role="tab" style="--h:<?= $hueOf[$b['id']] ?>" data-tab="<?= $b['id'] ?>" title="<?= e($b['name']) ?>">
      <?= e($b['name']) ?><span class="cnt"><?= (int)($s['available'] ?? 0) ?>/<?= (int)($s['_all'] ?? 0) ?></span>
    </button>
  <?php endforeach; ?>
</div>
<div class="legend"><?php foreach (ROOM_STATUS as [$l, $c]): ?><span><i style="background:<?= $c ?>"></i><?= e($l) ?></span><?php endforeach; ?><span style="margin-left:auto;color:#8A8F98">ตัวเลขบนแท็บ = ห้องว่าง / ห้องทั้งหมด</span></div>

<?php foreach ($buildings as $b): $s = $stat[$b['id']] ?? []; ?>
<section class="tabpanel <?= $b['id'] == $bid ? 'on' : '' ?>" id="tab-<?= $b['id'] ?>" role="tabpanel" style="--h:<?= $hueOf[$b['id']] ?>">
  <div class="bsum">
    <b><?= e($b['name']) ?></b> <code><?= e($b['code']) ?></code>
    <span>ทั้งหมด <?= (int)($s['_all'] ?? 0) ?> ห้อง</span>
    <?php foreach (ROOM_STATUS as $k => [$l, $c]): if (!empty($s[$k])): ?><span class="badge" style="background:#fff;border:1px solid var(--line);color:<?= $c ?>"><?= e($l) ?> <?= (int)$s[$k] ?></span><?php endif; endforeach; ?>
  </div>
  <?php if (empty($byB[$b['id']])): ?><div class="card" style="color:#8A8F98">อาคารนี้ยังไม่มีห้อง — กด "+ เพิ่มห้อง"</div><?php endif; ?>
  <?php foreach ($byB[$b['id']] ?? [] as $floor => $rooms): $free = count(array_filter($rooms, fn($x) => $x['status'] === 'available')); ?>
    <div class="floor">
      <h3>ชั้น <?= (int)$floor ?> <small><?= count($rooms) ?> ห้อง · ว่าง <?= $free ?></small></h3>
      <div class="rgrid">
      <?php foreach ($rooms as $r): [$l, $c] = ROOM_STATUS[$r['status']]; ?>
        <div class="card room" style="border-left-color:<?= $c ?>">
          <?php $rt = $rtypes[$r['room_type_id']] ?? null; if ($rt && $rt['src']): ?>
            <button type="button" class="rthumb" data-img="<?= e($rt['src']) ?>" data-cap="<?= e($rt['name'] . ' — ห้อง ' . $r['room_no'] . ($rt['note'] ? ' · ' . $rt['note'] : '')) ?>" title="ดูรูปห้องตัวอย่าง (<?= e($rt['name']) ?>)"><img src="<?= e($rt['src']) ?>" alt="<?= e($rt['name']) ?>" loading="lazy"></button>
          <?php endif; ?>
          <b><?= e($r['room_no']) ?></b><?= $r['type'] === 'meeting' ? ' <span class="mt">ห้องประชุม</span>' : '' ?>
          <div class="sub"><?= $r['beds'] ?> <?= $r['type'] === 'meeting' ? 'ที่นั่ง' : 'เตียง' ?></div>
          <?php if ($rtypes): ?>
          <form method="post" style="margin-bottom:4px"><?= csrf_field() ?><input type="hidden" name="action" value="room_rtype"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="b" value="<?= $b['id'] ?>">
            <select name="room_type_id" onchange="this.form.submit()" title="ประเภทห้อง" style="padding:4px 8px;font-size:11.5px"><option value="">— ประเภทห้อง —</option>
              <?php foreach ($rtypes as $t): ?><option value="<?= $t['id'] ?>" <?= $t['id'] == $r['room_type_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></form>
          <?php endif; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="b" value="<?= $b['id'] ?>">
            <select name="status" class="stsel" data-room="<?= e($r['room_no']) ?>" data-id="<?= $r['id'] ?>" data-b="<?= $b['id'] ?>" data-cur="<?= e($r['status']) ?>" data-note="<?= e((string)$r['status_note']) ?>" onchange="statusChange(this)" style="padding:5px 8px;font-size:12px;color:<?= $c ?>">
              <?php foreach (ROOM_STATUS as $k => [$lb]): ?><option value="<?= $k ?>" <?= $k === $r['status'] ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></form>
          <?php if (in_array($r['status'], ROOM_STATUS_WITH_NOTE, true)): ?>
            <div class="why" title="สาเหตุ"><span><?= $r['status_note'] !== null && $r['status_note'] !== '' ? e($r['status_note']) : '<i>ไม่ได้ระบุสาเหตุ</i>' ?></span>
              <button type="button" class="why-edit" title="แก้ไขสาเหตุ" data-why="<?= $r['id'] ?>" data-room="<?= e($r['room_no']) ?>" data-b="<?= $b['id'] ?>" data-status="<?= e($r['status']) ?>" data-note="<?= e((string)$r['status_note']) ?>">✎</button></div>
          <?php endif; ?>
          <div style="margin-top:8px;font-size:11.5px">
            <?php foreach ($units[$r['id']] ?? [] as $u): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="unit_del"><input type="hidden" name="unit" value="<?= $u['id'] ?>"><input type="hidden" name="b" value="<?= $b['id'] ?>">
              <span class="badge" style="background:#F7F3EE;color:#5B5450"><?= e($u['label']) ?> · <?= $u['beds'] ?> เตียง <button title="ลบห้องย่อย" style="border:0;background:none;cursor:pointer;color:#A32638" data-confirm="ลบห้องย่อยนี้?">×</button></span></form>
            <?php endforeach; ?>
            <details style="margin-top:4px"><summary style="cursor:pointer;color:var(--brand)">+ ห้องย่อย</summary>
              <form method="post" style="display:flex;gap:4px;margin-top:4px"><?= csrf_field() ?><input type="hidden" name="action" value="unit_add"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="b" value="<?= $b['id'] ?>">
              <input type="text" name="label" placeholder="A" style="width:56px;padding:5px 7px" required><input type="number" name="ubeds" value="2" min="1" style="width:52px;padding:5px 7px" title="จำนวนเตียง"><button class="btn" style="padding:5px 10px">เพิ่ม</button></form></details>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
<dialog id="dlgReason" class="modal modal-sm">
  <form method="post" id="reasonForm"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id"><input type="hidden" name="b"><input type="hidden" name="status">
    <div class="modal-h"><b id="rsTitle">ระบุสาเหตุ</b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
    <div id="rsHint" style="font-size:13px;color:#5B5450;margin-bottom:4px"></div>
    <label id="rsLbl">สาเหตุ</label><input type="text" name="reason" maxlength="255" placeholder="เช่น ใช้ในภารกิจฝึกอบรมอื่น / ซ่อมแอร์" autocomplete="off">
    <div class="modal-f"><button type="button" class="btn ghost" data-close>ยกเลิก</button><button class="btn">บันทึก</button></div>
  </form>
</dialog>
<dialog id="dlgBuilding" class="modal">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="building">
    <div class="modal-h"><b>เพิ่มอาคาร</b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
    <div class="row"><div><label>รหัสอาคาร</label><input type="text" name="code" required maxlength="20" autofocus></div>
    <div><label>ชื่ออาคาร</label><input type="text" name="name" required maxlength="120"></div>
    <div><label>จำนวนชั้น</label><input type="number" name="floors" value="1" min="1"></div></div>
    <div class="modal-f"><button type="button" class="btn ghost" data-close>ยกเลิก</button><button class="btn">เพิ่มอาคาร</button></div>
  </form>
</dialog>
<dialog id="dlgRoom" class="modal">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="room"><input type="hidden" name="b" value="<?= $bid ?>">
    <div class="modal-h"><b>เพิ่มห้อง</b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
    <div class="row"><div><label>อาคาร</label><select name="building_id"><?php foreach ($buildings as $b): ?><option value="<?= $b['id'] ?>" <?= $b['id'] == $bid ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>เลขห้อง/ชื่อห้อง</label><input type="text" name="room_no" required maxlength="30"></div>
    <div><label>ชั้น</label><input type="number" name="floor" value="1" min="1"></div>
    <div><label>จำนวนเตียง/ที่นั่ง</label><input type="number" name="beds" value="2" min="1"></div>
    <div><label>ประเภท</label><select name="type"><option value="lodging">ห้องพัก</option><option value="meeting">ห้องประชุม</option></select></div>
    <div><label>ประเภทห้อง (รูปตัวอย่าง)</label><select name="room_type_id"><option value="">— ไม่ระบุ —</option><?php foreach ($rtypes as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div></div>
    <p style="font-size:12px;color:#8A8F98">ต้องการแยกห้องนอนภายในห้อง (เช่น A, B) ให้เพิ่มห้องก่อน แล้วกด "+ ห้องย่อย" ที่การ์ดห้อง</p>
    <div class="modal-f"><button type="button" class="btn ghost" data-close>ยกเลิก</button><button class="btn" <?= $buildings ? '' : 'disabled' ?>>เพิ่มห้อง</button></div>
  </form>
</dialog>
<dialog id="dlgTypes" class="modal modal-lg">
  <div class="modal-h"><b>ประเภทห้อง / รูปห้องตัวอย่าง</b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
  <p style="font-size:12.5px;color:#8A8F98;margin-top:0">อัปโหลดรูปห้องตัวอย่าง 1 รูปต่อประเภท แล้วเลือกประเภทที่การ์ดห้อง — ทุกห้องประเภทเดียวกันจะแสดงรูปนี้</p>
  <?php foreach ($rtypes as $t): ?>
    <div class="rtype">
      <?php if ($t['src']): ?><button type="button" class="rthumb" data-img="<?= e($t['src']) ?>" data-cap="<?= e($t['name']) ?>"><img src="<?= e($t['src']) ?>" alt=""></button><?php else: ?><div class="rthumb none">ไม่มีรูป</div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="rtype-f"><?= csrf_field() ?><input type="hidden" name="action" value="rtype_save"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="b" value="<?= $bid ?>">
        <input type="text" name="name" value="<?= e($t['name']) ?>" required maxlength="100" title="ชื่อประเภท">
        <input type="text" name="note" value="<?= e((string)$t['note']) ?>" maxlength="255" placeholder="รายละเอียด (ไม่บังคับ)">
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" title="<?= $t['src'] ? 'เปลี่ยนรูป' : 'เพิ่มรูป' ?>">
        <span style="font-size:11.5px;color:#8A8F98">ใช้กับ <?= (int)$t['used'] ?> ห้อง</span>
        <button class="btn" style="padding:5px 12px">บันทึก</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rtype_del"><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="b" value="<?= $bid ?>">
        <button class="btn ghost" style="padding:5px 10px;color:#A32638" data-confirm="ลบประเภทห้อง &quot;<?= e($t['name']) ?>&quot;? (ห้องที่ใช้ประเภทนี้จะไม่มีประเภท)">ลบ</button></form>
    </div>
  <?php endforeach; ?>
  <form method="post" enctype="multipart/form-data" class="rtype rtype-new"><?= csrf_field() ?><input type="hidden" name="action" value="rtype_save"><input type="hidden" name="b" value="<?= $bid ?>">
    <div class="rtype-f">
      <input type="text" name="name" required maxlength="100" placeholder="ชื่อประเภทใหม่ เช่น ห้องเดี่ยว, ห้องคู่ VIP">
      <input type="text" name="note" maxlength="255" placeholder="รายละเอียด (ไม่บังคับ)">
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
      <button class="btn" style="padding:5px 12px">+ เพิ่มประเภท</button>
    </div>
  </form>
  <p style="font-size:11.5px;color:#8A8F98">รองรับ JPG, PNG, WEBP, GIF ขนาดไม่เกิน 5 MB</p>
</dialog>
<dialog id="dlgImport" class="modal">
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="b" value="<?= $bid ?>">
    <div class="modal-h"><b>นำเข้าข้อมูลอาคารและห้อง (ZIP)</b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
    <label>ไฟล์ ZIP ที่ส่งออกจากระบบ</label><input type="file" name="zip" accept=".zip,application/zip" required>
    <ul style="font-size:12.5px;color:#5B5450;padding-left:18px">
      <li>อาคารจับคู่ด้วย<b>รหัสอาคาร</b> ห้องจับคู่ด้วย<b>อาคาร + เลขห้อง</b> ประเภทห้องจับคู่ด้วย<b>ชื่อ</b></li>
      <li>ข้อมูลที่มีอยู่แล้วจะถูกอัปเดต ที่ยังไม่มีจะถูกเพิ่ม — ไม่ลบข้อมูลเดิม</li>
      <li>สถานะห้องที่มีอยู่แล้วคงเดิม (ใช้สถานะจากไฟล์กับห้องใหม่เท่านั้น)</li>
    </ul>
    <div class="modal-f"><button type="button" class="btn ghost" data-close>ยกเลิก</button><button class="btn">นำเข้า</button></div>
  </form>
</dialog>
<dialog id="dlgImg" class="modal modal-lg">
  <div class="modal-h"><b id="imgCap"></b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
  <img id="imgBig" alt="" style="width:100%;max-height:70vh;object-fit:contain;border-radius:10px;background:#F7F3EE">
</dialog>
<script>
// รูปห้องตัวอย่าง: คลิกรูปย่อเพื่อดูภาพใหญ่
document.querySelectorAll('[data-img]').forEach(function(b){b.onclick=function(){
  document.getElementById('imgBig').src=b.dataset.img;document.getElementById('imgCap').textContent=b.dataset.cap;
  document.getElementById('dlgImg').showModal()}});
// แท็บอาคาร: สลับโดยไม่โหลดหน้าใหม่ จำแท็บล่าสุดไว้ และอัปเดต URL (?b=) ให้แชร์/รีเฟรชแล้วอยู่แท็บเดิม
var activeB=<?= (int)$bid ?>;
function showTab(id){
  var ok=false;document.querySelectorAll('.tab').forEach(function(t){var on=t.dataset.tab==id;t.classList.toggle('on',on);ok=ok||on});
  if(!ok)return;activeB=id;
  document.querySelectorAll('.tabpanel').forEach(function(p){p.classList.toggle('on',p.id==='tab-'+id)});
  try{localStorage.setItem('roomsTab',id)}catch(e){}
  history.replaceState(null,'','?b='+id);
}
document.querySelectorAll('.tab').forEach(function(t){t.onclick=function(){showTab(t.dataset.tab)}});
var qb=new URLSearchParams(location.search).get('b');
if(qb){showTab(qb)}else{try{var sv=localStorage.getItem('roomsTab');if(sv)showTab(sv)}catch(e){}}
// สถานะที่ต้องระบุสาเหตุ: เลือก "ไม่ว่าง"/"ปิดซ่อม" แล้วเปิดกล่องให้กรอกก่อนบันทึก (ยกเลิก = คืนค่าเดิม)
var NEED={unavailable:{t:'ตั้งห้องเป็น "ไม่ว่าง"',h:'ห้องนี้ถูกใช้งานในภารกิจอื่น ไม่นำมาจัดให้ผู้เข้าพัก',req:true},maintenance:{t:'ตั้งห้องเป็น "ปิดซ่อม"',h:'ห้องนี้ไม่พร้อมใช้งาน',req:false}};
function openReason(id,b,room,status,note){
  var d=document.getElementById('dlgReason'),f=document.getElementById('reasonForm'),n=NEED[status];
  f.id.value=id;f.b.value=b;f.status.value=status;f.reason.value=note||'';f.reason.required=n.req;
  document.getElementById('rsTitle').textContent=n.t+' — ห้อง '+room;document.getElementById('rsHint').textContent=n.h;
  document.getElementById('rsLbl').textContent=n.req?'สาเหตุ (จำเป็น)':'สาเหตุ (ไม่บังคับ)';
  d.showModal();f.reason.focus();return d;
}
var pendingSel=null;
function revertPending(){if(pendingSel){pendingSel.value=pendingSel.dataset.cur;pendingSel=null}}
(function(){ // ปิดกล่องโดยไม่บันทึก (ยกเลิก / ✕ / Esc / คลิกพื้นหลัง) → คืนค่าสถานะเดิมในช่องเลือก
  var d=document.getElementById('dlgReason');
  d.addEventListener('close',revertPending);d.addEventListener('cancel',revertPending);
  d.querySelectorAll('[data-close]').forEach(function(x){x.addEventListener('click',revertPending)});
  d.addEventListener('click',function(e){if(e.target===d)revertPending()});
  document.getElementById('reasonForm').addEventListener('submit',function(){pendingSel=null});
})();
function statusChange(sel){
  if(NEED[sel.value]){revertPending();pendingSel=sel;openReason(sel.dataset.id,sel.dataset.b,sel.dataset.room,sel.value,sel.dataset.cur===sel.value?sel.dataset.note:'')}
  else sel.form.submit();
}
document.querySelectorAll('[data-why]').forEach(function(b){b.onclick=function(){openReason(b.dataset.why,b.dataset.b,b.dataset.room,b.dataset.status,b.dataset.note)}});
document.querySelectorAll('[data-open]').forEach(function(b){b.onclick=function(){
  var d=document.getElementById(b.dataset.open);
  if(d.id==='dlgRoom'){d.querySelector('[name=building_id]').value=activeB;d.querySelector('[name=b]').value=activeB} // เพิ่มห้องในอาคารของแท็บที่เปิดอยู่
  d.showModal()}});
document.querySelectorAll('dialog.modal').forEach(function(d){
  d.addEventListener('click',function(e){if(e.target===d)d.close()}); // คลิกพื้นหลังเพื่อปิด
  d.querySelectorAll('[data-close]').forEach(function(x){x.onclick=function(){d.close()}});
});
</script>
<?php app_end();
