<?php
declare(strict_types=1);

/**
 * ภาพจำลองอาคาร: แต่ละอาคารเป็นภาพตัดขวาง เรียงชั้นจากบนลงล่าง ห้องแต่ละห้องเป็นช่องสีตามสถานะ
 * จำนวนชั้นมาจากข้อมูลห้องจริง (ชั้นสูงสุดที่มีห้อง) — ชั้นที่ยังไม่มีห้องแสดงเป็นแถวว่าง
 */
function building_map(PDO $db, bool $linkRooms): void
{
    $buildings = $db->query('SELECT * FROM buildings ORDER BY code')->fetchAll();
    if (!$buildings) {
        return;
    }
    $rooms = [];
    foreach ($db->query('SELECT id,building_id,room_no,floor,beds,type,status,status_note FROM rooms ORDER BY floor,room_no')->fetchAll() as $r) {
        $rooms[$r['building_id']][(int)$r['floor']][] = $r;
    }
    $hues = [350, 205, 150, 32, 270, 175, 15, 230]; // โทนสีอาคารเดียวกับหน้าสถานะห้องพัก
    $total = array_fill_keys(array_keys(ROOM_STATUS), 0);
    foreach ($rooms as $byFloor) {
        foreach ($byFloor as $list) {
            foreach ($list as $r) {
                $total[$r['status']]++;
            }
        }
    }
    ?>
<div class="card bmap">
  <div class="bmap-head">
    <div><b>ภาพจำลองอาคารและสถานะห้องพัก</b><div class="bmap-sub">ชี้ที่ห้องเพื่อดูรายละเอียด · คลิกสถานะด้านขวาเพื่อไฮไลต์เฉพาะสถานะนั้น</div></div>
    <div class="bmap-legend" id="bmapLegend">
      <?php foreach (ROOM_STATUS as $k => [$label, $color]): ?>
        <button type="button" class="lg" data-st="<?= e($k) ?>" title="ไฮไลต์: <?= e($label) ?>"><i class="sw st-<?= e($k) ?>" style="background:<?= e($color) ?>"></i><?= e($label) ?> <b><?= (int)$total[$k] ?></b></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="bmap-grid">
    <?php foreach ($buildings as $bi => $b):
        $byFloor = $rooms[$b['id']] ?? [];
        $maxFloor = $byFloor ? max(array_keys($byFloor)) : 0;
        $cnt = ['_all' => 0];
        foreach ($byFloor as $list) {
            foreach ($list as $r) {
                $cnt[$r['status']] = ($cnt[$r['status']] ?? 0) + 1;
                $cnt['_all']++;
            }
        }
        $hue = $hues[$bi % count($hues)];
        ?>
      <div class="bldg" style="--h:<?= $hue ?>">
        <div class="bldg-title">
          <span><b><?= e($b['name']) ?></b> <code><?= e($b['code']) ?></code></span>
          <span class="bldg-cnt">ว่าง <b><?= (int)($cnt['available'] ?? 0) ?></b>/<?= $cnt['_all'] ?> ห้อง · <?= $maxFloor ?: '-' ?> ชั้น</span>
        </div>
        <div class="roof"></div>
        <div class="bldg-body">
          <?php if (!$maxFloor): ?>
            <div class="floor-row empty"><span class="fl">-</span><span class="none">ยังไม่มีห้องพักในอาคารนี้</span></div>
          <?php endif; ?>
          <?php for ($f = $maxFloor; $f >= 1; $f--): $list = $byFloor[$f] ?? []; ?>
            <div class="floor-row <?= $list ? '' : 'empty' ?>">
              <span class="fl">ชั้น <?= $f ?></span>
              <div class="cells">
                <?php if (!$list): ?><span class="none">ไม่มีห้องพักที่ลงทะเบียน</span><?php endif; ?>
                <?php foreach ($list as $r):
                    $tip = "{$b['code']}-{$r['room_no']} · " . ROOM_STATUS[$r['status']][0] . ' · ' . $r['beds'] . ($r['type'] === 'meeting' ? ' ที่นั่ง (ห้องประชุม)' : ' เตียง')
                        . (!empty($r['status_note']) ? ' · สาเหตุ: ' . $r['status_note'] : '');
                    $tag = $linkRooms ? 'a' : 'span';
                    $href = $linkRooms ? ' href="' . e(url('rooms.php?b=' . $b['id'])) . '"' : '';
                    ?>
                  <<?= $tag ?> class="cell st-<?= e($r['status']) ?><?= $r['type'] === 'meeting' ? ' meet' : '' ?>" data-st="<?= e($r['status']) ?>" title="<?= e($tip) ?>"<?= $href ?>><?= e($r['room_no']) ?></<?= $tag ?>>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endfor; ?>
        </div>
        <div class="bldg-base"></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function(){ // ไฮไลต์เฉพาะสถานะที่เลือก (คลิกซ้ำเพื่อยกเลิก)
  var root=document.querySelector('.bmap'),cur=null;
  root.querySelectorAll('#bmapLegend .lg').forEach(function(b){b.onclick=function(){
    cur=cur===b.dataset.st?null:b.dataset.st;
    root.classList.toggle('filtering',!!cur);
    root.querySelectorAll('#bmapLegend .lg').forEach(function(x){x.classList.toggle('on',x.dataset.st===cur)});
    root.querySelectorAll('.cell').forEach(function(c){c.classList.toggle('hit',!!cur&&c.dataset.st===cur)});
  }});
})();
</script>
<?php
}
