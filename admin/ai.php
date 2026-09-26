<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/views/layout.php';

$me = Auth::require('admin');
$db = Db::pdo();
$reopen = null; // ค่าที่กรอกไว้ กรณีบันทึกโปรไฟล์ไม่ผ่านการทดสอบ (เปิดกล่องเดิมให้แก้ต่อ)

try {
    switch ($act = post_action()) {
        case 'save_profile':
            $id = (int)($_POST['id'] ?? 0);
            $f = [
                'name' => trim($_POST['name'] ?? ''),
                'provider' => isset(AI_PROVIDERS[$_POST['provider'] ?? '']) ? $_POST['provider'] : 'custom',
                'base' => rtrim(trim($_POST['base_url'] ?? ''), '/'),
                'model' => trim($_POST['model'] ?? ''),
                'key' => trim($_POST['api_key'] ?? ''),
            ];
            $reopen = ['id' => $id] + $f + ['key' => ''];
            if ($f['name'] === '' || mb_strlen($f['name']) > 80 || $f['model'] === '' || !preg_match('#^https?://#i', $f['base'])) {
                throw new RuntimeException('กรอกชื่อ, Base URL (ขึ้นต้น http:// หรือ https://) และโมเดลให้ครบ');
            }
            $cur = $id ? ai_profile($id) : null;
            if ($id && !$cur) {
                throw new RuntimeException('ไม่พบ API ที่ต้องการแก้ไข');
            }
            if (!$id && $f['key'] === '') {
                throw new RuntimeException('กรุณากรอก API key');
            }
            // ทดสอบก่อนบันทึกเมื่อเป็นรายการใหม่หรือแก้ค่าที่มีผลต่อการเชื่อมต่อ
            $newCfg = ai_cfg(['profile' => $id ?: -1, 'provider' => $f['provider'], 'base' => $f['base'], 'key' => $f['key'], 'model' => $f['model']]);
            if ((!$id || $newCfg !== ai_cfg(['profile' => $id])) && empty($_POST['skip_test'])) {
                $t = ai_chat([['role' => 'user', 'content' => 'สวัสดี']], $newCfg);
                if (!$t['ok']) {
                    throw new RuntimeException('ไม่บันทึก: ทดสอบการเชื่อมต่อไม่ผ่าน — ' . $t['text'] . ' (แก้ไขค่า หรือติ๊ก "บันทึกโดยไม่ทดสอบ")');
                }
            }
            if ($id) {
                $db->prepare('UPDATE ai_profiles SET name=?,provider=?,base_url=?,model=?,api_key=COALESCE(NULLIF(?,\'\'),api_key) WHERE id=?')
                    ->execute([$f['name'], $f['provider'], $f['base'], $f['model'], $f['key'], $id]);
                audit("แก้ไข API ผู้ช่วย AI: {$f['name']}");
            } else {
                $db->prepare('INSERT INTO ai_profiles (name,provider,base_url,api_key,model,sort) VALUES (?,?,?,?,?,?)')
                    ->execute([$f['name'], $f['provider'], $f['base'], $f['key'], $f['model'], (int)$db->query('SELECT COALESCE(MAX(sort),0)+1 FROM ai_profiles')->fetchColumn()]);
                audit("เพิ่ม API ผู้ช่วย AI: {$f['name']}");
                if (!setting('ai_default_profile')) { // รายการแรกเป็นค่าเริ่มต้น
                    save_setting('ai_default_profile', (string)$db->lastInsertId());
                    save_setting('ai_simple_profile', (string)$db->lastInsertId());
                }
            }
            flash('success', 'บันทึก API แล้ว');
            redirect('admin/ai.php');
        case 'toggle':
            $db->prepare('UPDATE ai_profiles SET enabled=1-enabled WHERE id=?')->execute([(int)$_POST['id']]);
            audit('สลับการใช้งาน API ผู้ช่วย AI #' . (int)$_POST['id']);
            redirect('admin/ai.php');
        case 'delete':
            $db->prepare('DELETE FROM ai_profiles WHERE id=?')->execute([(int)$_POST['id']]);
            audit('ลบ API ผู้ช่วย AI #' . (int)$_POST['id']);
            flash('success', 'ลบ API แล้ว');
            redirect('admin/ai.php');
        case 'general':
            $ids = array_map('intval', array_column(ai_profiles(true), 'id'));
            $def = (int)($_POST['default_profile'] ?? 0);
            $simple = (int)($_POST['simple_profile'] ?? 0);
            $mode = ($_POST['select_mode'] ?? 'user') === 'auto' ? 'auto' : 'user';
            if ($mode === 'auto' && (!in_array($def, $ids, true) || !in_array($simple, $ids, true))) {
                throw new RuntimeException('โหมดอัตโนมัติต้องเลือก API ทั้งสำหรับคำถามสั้นและคำถามทั่วไป (ที่เปิดใช้งานอยู่)');
            }
            save_setting('ai_enabled', empty($_POST['ai_enabled']) ? '0' : '1');
            save_setting('ai_cost', (string)max(0, round((float)($_POST['ai_cost'] ?? 5), 2)));
            save_setting('ai_select_mode', $mode);
            save_setting('ai_default_profile', in_array($def, $ids, true) ? (string)$def : setting('ai_default_profile'));
            save_setting('ai_simple_profile', in_array($simple, $ids, true) ? (string)$simple : setting('ai_simple_profile'));
            save_setting('ai_simple_max', (string)min(500, max(1, (int)($_POST['simple_max'] ?? 30))));
            audit('แก้ไขการตั้งค่าผู้ช่วย AI (โหมดเลือก: ' . $mode . ')');
            flash('success', 'บันทึกการตั้งค่าแล้ว');
            redirect('admin/ai.php');
    }
} catch (Throwable $ex) {
    flash('error', $ex->getMessage());
}

$profiles = ai_profiles();
$mask = fn(string $v) => '••••' . substr($v, -4);
$byId = array_column($profiles, null, 'id');
$defId = ai_default_profile_id();
$simpleId = (int)setting('ai_simple_profile') ?: $defId;
$mode = setting('ai_select_mode', 'user');
$logs = $db->query('SELECT a.*,u.full_name FROM ai_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 20')->fetchAll();
$total = (float)$db->query('SELECT COALESCE(SUM(cost),0) FROM ai_log')->fetchColumn();
$jsProfiles = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'provider' => $p['provider'], 'base_url' => $p['base_url'], 'model' => $p['model']], $profiles);

// ตรวจ PHP extension ที่ต้องใช้เชื่อมต่อ API ของ AI
$curlSsl = function_exists('curl_version') && (curl_version()['features'] & CURL_VERSION_SSL);
$reqs = [
    ['curl', extension_loaded('curl'), 'ใช้เรียก API ของ AI (จำเป็น)'],
    ['curl + SSL/HTTPS', (bool)$curlSsl, 'curl ต้องรองรับ HTTPS เพื่อเชื่อมต่อ https://…'],
    ['openssl', extension_loaded('openssl'), 'ใช้เข้ารหัสการเชื่อมต่อ HTTPS'],
    ['json', function_exists('json_encode'), 'ใช้รับ-ส่งข้อมูลกับ API'],
    ['mbstring', extension_loaded('mbstring'), 'ใช้จัดการข้อความภาษาไทย'],
];
$reqFail = array_filter($reqs, fn($r) => !$r[1]);

app_start('ผู้ช่วย AI (API)', $me, 'ai');
?>
<div class="card" style="margin-bottom:16px<?= $reqFail ? ';border:1px solid #c0392b' : '' ?>">
  <b>แพ็กเกจที่จำเป็นสำหรับการเชื่อมต่อ</b>
  <span style="font-size:12px;color:<?= $reqFail ? '#c0392b' : '#2e7d32' ?>"> — <?= $reqFail ? 'ขาด ' . count($reqFail) . ' รายการ: ติดตั้ง/เปิดใช้ใน php.ini แล้วรีสตาร์ตเว็บเซิร์ฟเวอร์' : 'พร้อมใช้งานครบ' ?></span>
  <ul style="margin:8px 0 0;padding-left:0;list-style:none;font-size:13px">
    <?php foreach ($reqs as [$n, $ok, $d]): ?>
      <li><?= $ok ? '✅' : '❌' ?> <b><?= e($n) ?></b> <span style="color:#8A8F98">— <?= e($d) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <div style="font-size:12px;color:#8A8F98;margin-top:6px">PHP <?= e(PHP_VERSION) ?><?= function_exists('curl_version') ? ' · curl ' . e(curl_version()['version']) . ' · ' . e((string)curl_version()['ssl_version']) : '' ?></div>
</div>
<div class="actions" style="margin-bottom:16px"><button type="button" class="btn" id="btnAdd">+ เพิ่ม API</button></div>

<div class="card" style="padding:0;overflow:auto"><table>
<tr><th>ชื่อ</th><th>ผู้ให้บริการ</th><th>โมเดล</th><th>Base URL / คีย์</th><th>สถานะ</th><th></th></tr>
<?php foreach ($profiles as $p): ?>
<tr><td><b><?= e($p['name']) ?></b>
  <?php if ((int)$p['id'] === $defId): ?><span class="badge ok">ค่าเริ่มต้น</span><?php endif; ?>
  <?php if ($mode === 'auto' && (int)$p['id'] === $simpleId): ?><span class="badge pend">คำถามสั้น</span><?php endif; ?></td>
  <td><?= e(AI_PROVIDERS[$p['provider']][0] ?? $p['provider']) ?></td><td><code><?= e($p['model']) ?></code></td>
  <td style="font-size:12px"><?= e($p['base_url']) ?><br><span style="color:#8A8F98"><?= e($mask($p['api_key'])) ?></span></td>
  <td><span class="badge <?= $p['enabled'] ? 'ok' : 'bad' ?>"><?= $p['enabled'] ? 'เปิดใช้งาน' : 'ปิด' ?></span></td>
  <td><div class="actions">
    <button type="button" class="btn ghost" style="padding:6px 12px" data-edit="<?= (int)$p['id'] ?>">แก้ไข</button>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn ghost" style="padding:6px 12px" name="action" value="toggle"><?= $p['enabled'] ? 'ปิด' : 'เปิด' ?></button></form>
    <form method="post" data-confirm="ลบ API นี้?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn ghost" style="padding:6px 12px" name="action" value="delete">ลบ</button></form>
  </div></td></tr>
<?php endforeach; if (!$profiles): ?><tr><td colspan="6">ยังไม่มี API — กด "+ เพิ่ม API"</td></tr><?php endif; ?>
</table></div>

<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="general">
  <h2 style="margin-top:0">การใช้งานและการเลือก API</h2>
  <label style="display:flex;gap:8px;align-items:center;color:#1F1B1A"><input type="checkbox" name="ai_enabled" value="1" <?= setting('ai_enabled') === '1' ? 'checked' : '' ?>> เปิดใช้ผู้ช่วย AI</label>
  <label style="margin-top:16px">วิธีเลือก API ที่ตอบคำถาม</label>
  <label style="display:flex;gap:8px;align-items:flex-start;color:#1F1B1A;margin:6px 0"><input type="radio" name="select_mode" value="user" <?= $mode !== 'auto' ? 'checked' : '' ?>><span><b>ผู้ใช้เลือกเอง</b> — แสดงรายการ API ในกล่องแชท ให้ผู้ใช้เลือกปลายทางที่ต้องการ (ใช้ "ค่าเริ่มต้น" ถ้าไม่ได้เลือก)</span></label>
  <label style="display:flex;gap:8px;align-items:flex-start;color:#1F1B1A;margin:6px 0"><input type="radio" name="select_mode" value="auto" <?= $mode === 'auto' ? 'checked' : '' ?>><span><b>เลือกอัตโนมัติ</b> — คำถามสั้นไม่เกินจำนวนตัวอักษรที่กำหนดใช้ API สำหรับคำถามสั้น นอกนั้นใช้ API ค่าเริ่มต้น (ผู้ใช้เลือกเองไม่ได้)</span></label>
  <div class="row" style="margin-top:8px">
    <div><label>API ค่าเริ่มต้น / คำถามทั่วไป</label><select name="default_profile"><?php foreach (ai_profiles(true) as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $defId ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>API สำหรับคำถามสั้น (โหมดอัตโนมัติ)</label><select name="simple_profile"><?php foreach (ai_profiles(true) as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $simpleId ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>ถือว่า "สั้น" เมื่อไม่เกิน (ตัวอักษร)</label><input type="number" name="simple_max" min="1" max="500" value="<?= e(setting('ai_simple_max', '30')) ?>"></div>
    <div><label>ค่าตอบแทนโดยประมาณต่อการดำเนินการ (บาท)</label><input type="number" step="0.5" min="0" name="ai_cost" value="<?= e(setting('ai_cost', '5')) ?>"></div>
  </div>
  <p style="font-size:12px;color:#8A8F98">คำสั่งจัดการอาคาร/ห้องที่ซับซ้อนหลายรายการ ต้องใช้ API ที่รองรับ (เช่น OpenAI, Gemini, OpenRouter) — ThaiLLM จะเตือนให้เปลี่ยนหรือแบ่งสั่งทีละรายการ</p>
  <p><button class="btn">บันทึกการตั้งค่า</button></p>
</form>

<div class="card"><b>บันทึกการใช้งาน AI</b> <span style="color:#8A8F98;font-size:12px">— รวมค่าใช้จ่ายประมาณ <?= number_format($total, 2) ?> บาท</span>
<table style="margin-top:8px"><tr><th>เวลา</th><th>ผู้ใช้</th><th>API</th><th>คำถาม</th><th>ค่าใช้จ่าย</th></tr>
<?php foreach ($logs as $a): ?><tr><td><?= e($a['created_at']) ?></td><td><?= e($a['full_name']) ?></td><td><?= e($a['profile']) ?></td><td><?= e($a['prompt']) ?></td><td><?= e($a['cost']) ?> บ.</td></tr><?php endforeach; ?>
<?php if (!$logs): ?><tr><td colspan="5">ยังไม่มีการใช้งาน</td></tr><?php endif; ?></table></div>

<dialog id="dlgProfile" class="modal" style="width:min(680px,calc(100vw - 32px))">
  <form method="post" id="pform"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><input type="hidden" name="id" value="0">
    <div class="modal-h"><b id="ptitle">เพิ่ม API</b><button type="button" class="modal-x" data-close aria-label="ปิด">✕</button></div>
    <div class="row">
      <div><label>ชื่อที่แสดง (เช่น "เร็ว/ประหยัด", "ฉลาด")</label><input type="text" name="name" required maxlength="80"></div>
      <div><label>ผู้ให้บริการ</label><select name="provider" id="prov"><?php foreach (AI_PROVIDERS as $k => $p): ?><option value="<?= $k ?>"><?= e($p[0]) ?></option><?php endforeach; ?></select></div>
      <div><label>Base URL</label><input type="text" name="base_url" id="base" required></div>
      <div><label id="keylbl">API key</label><input type="password" name="api_key" autocomplete="new-password"></div>
      <div style="grid-column:1/-1"><label>โมเดล (พิมพ์ค้นหา/เลือกจากรายการที่ดึงมา)</label><input type="text" name="model" id="model" list="models" autocomplete="off" required><datalist id="models"></datalist></div>
    </div>
    <div class="actions" style="margin-top:12px"><button type="button" class="btn ghost" id="btnModels">1. ตรวจสอบการเชื่อมต่อ / ดึงรายการโมเดล</button><button type="button" class="btn ghost" id="btnChat">2. ทดสอบโมเดลที่เลือก</button></div>
    <div id="aistat" style="font-size:12.5px;margin:10px 0;min-height:20px"></div>
    <label style="display:flex;gap:8px;align-items:center;color:#1F1B1A"><input type="checkbox" name="skip_test" value="1"> บันทึกโดยไม่ทดสอบ (ปกติระบบทดสอบให้อัตโนมัติเมื่อเพิ่ม/แก้ค่าที่เชื่อมต่อ)</label>
    <div class="modal-f"><button type="button" class="btn ghost" data-close>ยกเลิก</button><button class="btn">บันทึก</button></div>
  </form>
</dialog>
<script>
var P=<?= json_encode(array_map(fn($p) => ['u' => $p[1], 'm' => $p[2]], AI_PROVIDERS)) ?>,
    ROWS=<?= json_encode($jsProfiles, JSON_UNESCAPED_UNICODE) ?>,
    REOPEN=<?= json_encode($reopen, JSON_UNESCAPED_UNICODE) ?>,
    $=function(i){return document.getElementById(i)},d=$('dlgProfile'),f=$('pform'),st=$('aistat');
function openForm(v){
  v=v||{id:0,name:'',provider:'openrouter',base_url:P.openrouter.u,model:P.openrouter.m};
  f.id.value=v.id;f.name.value=v.name;f.provider.value=v.provider;f.base_url.value=v.base_url;f.model.value=v.model;f.api_key.value='';f.skip_test.checked=false;
  $('ptitle').textContent=v.id?'แก้ไข API':'เพิ่ม API';$('keylbl').textContent=v.id?'API key (เว้นว่าง = ไม่เปลี่ยน)':'API key';
  f.api_key.required=!v.id;$('models').innerHTML='';st.textContent='';d.showModal();
}
$('btnAdd').onclick=function(){openForm()};
document.querySelectorAll('[data-edit]').forEach(function(b){b.onclick=function(){openForm(ROWS.find(function(r){return r.id==b.dataset.edit}))}});
d.addEventListener('click',function(e){if(e.target===d)d.close()});
d.querySelectorAll('[data-close]').forEach(function(x){x.onclick=function(){d.close()}});
f.provider.onchange=function(){var p=P[this.value];if(this.value!=='custom'){f.base_url.value=p.u;f.model.value=p.m}$('models').innerHTML=''};
function say(t,ok){st.textContent=t;st.style.color=ok?'#2F6B4F':'#A32638'}
function call(action,btn){
  btn.disabled=true;say('กำลังทดสอบ...',true);
  return fetch(<?= json_encode(url('admin/ai_test.php')) ?>,{method:'POST',headers:{'X-CSRF-Token':<?= json_encode(csrf_token()) ?>},
    body:JSON.stringify({action:action,profile:+f.id.value||0,provider:f.provider.value,base:f.base_url.value,key:f.api_key.value,model:f.model.value})})
  .then(function(r){return r.json()}).catch(function(){return{ok:false,error:'เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ'}}).then(function(j){btn.disabled=false;return j});
}
$('btnModels').onclick=function(){call('models',this).then(function(j){
  if(!j.ok)return say('เชื่อมต่อไม่สำเร็จ: '+j.error,false);
  $('models').innerHTML='';j.models.forEach(function(m){var o=document.createElement('option');o.value=m;$('models').appendChild(o)});
  say('เชื่อมต่อสำเร็จ — พบ '+j.models.length+' โมเดล (คลิกช่องโมเดลเพื่อเลือก)',true)})};
$('btnChat').onclick=function(){call('chat',this).then(function(j){say(j.ok?'โมเดลตอบสำเร็จ: '+j.reply:'ทดสอบไม่สำเร็จ: '+j.error,j.ok)})};
if(REOPEN){openForm({id:REOPEN.id,name:REOPEN.name,provider:REOPEN.provider,base_url:REOPEN.base,model:REOPEN.model})}
</script>
<?php app_end();
