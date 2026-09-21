<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

$me = Auth::require('owner');
$db = Db::pdo();

// โหมดแก้ไข: แก้ได้เฉพาะคำขอของตนที่ยังรอตรวจสอบ
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$edit = null;
if ($editId) {
    $st = $db->prepare("SELECT * FROM projects WHERE id=? AND owner_id=? AND status='pending'");
    $st->execute([$editId, $me['id']]);
    $edit = $st->fetch() ?: null;
    if (!$edit) {
        flash('error', 'แก้ไขไม่ได้ — คำขอนี้ไม่ใช่ของคุณหรืออยู่ระหว่างตรวจสอบ/พิจารณาแล้ว');
        redirect('my_bookings.php');
    }
}

if (post_action() === 'save') {
    $p = [
        'name' => trim($_POST['name'] ?? ''), 'start_date' => $_POST['start_date'] ?? '', 'end_date' => $_POST['end_date'] ?? '',
        'meeting_room_id' => (int)($_POST['meeting_room_id'] ?? 0) ?: null,
    ];
    $n = fn(string $k) => max(0, (int)($_POST[$k] ?? 0));
    $ok = $p['name'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['end_date']) && $p['end_date'] >= $p['start_date'];
    if (!$ok) {
        flash('error', 'กรุณากรอกชื่อโครงการและวันที่ให้ถูกต้อง (วันสิ้นสุดต้องไม่ก่อนวันเริ่ม)');
    } else {
        if ($edit) {
            $db->prepare('UPDATE projects SET name=?,start_date=?,end_date=?,meeting_room_id=?,male=?,female=?,rooms_trainee=?,rooms_speaker=?,rooms_committee=?,note=? WHERE id=?')
                ->execute([$p['name'], $p['start_date'], $p['end_date'], $p['meeting_room_id'], $n('male'), $n('female'),
                    $n('rooms_trainee'), $n('rooms_speaker'), $n('rooms_committee'), trim($_POST['note'] ?? '') ?: null, $editId]);
            audit('แก้ไขคำขอจอง #' . $editId);
            notify('คำขอจองถูกแก้ไข: ' . $p['name'], "{$me['full_name']} แก้ไขคำขอจอง {$edit['booking_no']}", caretaker_emails(), true);
            flash('success', 'บันทึกการแก้ไขแล้ว');
            redirect('my_bookings.php');
        }
        $db->prepare('INSERT INTO projects (name,owner_id,start_date,end_date,meeting_room_id,male,female,rooms_trainee,rooms_speaker,rooms_committee,note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$p['name'], $me['id'], $p['start_date'], $p['end_date'], $p['meeting_room_id'], $n('male'), $n('female'),
                $n('rooms_trainee'), $n('rooms_speaker'), $n('rooms_committee'), trim($_POST['note'] ?? '') ?: null]);
        $id = (int)$db->lastInsertId();
        $no = 'BK' . date('ymd') . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE projects SET booking_no=? WHERE id=?')->execute([$no, $id]);
        $p['id'] = $id;
        audit("ยื่นคำขอจอง $no: {$p['name']}");
        notify("คำขอจองใหม่ $no", "{$me['full_name']} ยื่นคำขอ: {$p['name']} ({$p['start_date']} ถึง {$p['end_date']})", caretaker_emails(), true);
        flash('success', "บันทึกการจองเบื้องต้นแล้ว เลขที่ $no");
        if (project_conflicts($p)) {
            flash('warn', 'พบการจองห้องประชุมซ้อนช่วงเวลาเดียวกัน — รอผู้ดูแลตรวจสอบและแจ้งผล');
        }
        redirect('my_bookings.php');
    }
}

$meeting = $db->query("SELECT r.id,r.room_no,b.name bn FROM rooms r JOIN buildings b ON b.id=r.building_id WHERE r.type='meeting' AND r.status<>'maintenance' ORDER BY b.code,r.room_no")->fetchAll();
$v = fn(string $k, string $d = '') => e((string)($_POST[$k] ?? $edit[$k] ?? $d));
app_start($edit ? 'แก้ไขคำขอจอง' : 'จองห้องพักและห้องประชุม', $me, 'book');
$aiEnabled = setting('ai_enabled') === '1' && ai_default_profile_id();
?>
<?php if ($aiEnabled && !$edit): ?>
<div id="aiDraftWrap" style="max-width:760px;margin-bottom:16px">
  <button type="button" id="aiDraftToggle" class="btn ghost" style="width:100%;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border:1.5px dashed var(--line);border-radius:12px;background:#FDFBFB">
    <svg viewBox="0 0 24 24" width="18" height="18" style="flex-shrink:0"><path fill="var(--side)" d="M12 2a2 2 0 0 1 2 2v1h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2V4a2 2 0 0 1 2-2Zm0 2v1H10V4h2ZM6 7v10h12V7H6Zm6 2a1 1 0 1 1 0 2 1 1 0 0 1 0-2Zm-3 4h6a1 1 0 0 1 0 2H9a1 1 0 0 1 0-2Z"/></svg>
    <span>ให้ AI ช่วยร่างโครงการ</span>
    <svg id="aiDraftChev" viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M7 10l5 5 5-5"/></svg>
  </button>
  <div id="aiDraftPanel" style="display:none;border:1px solid var(--line);border-top:none;border-radius:0 0 12px 12px;background:#fff;overflow:hidden">
    <div id="aiDraftMsgs" style="min-height:80px;max-height:340px;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:9px;font-size:12.5px"></div>
    <form id="aiDraftForm" style="display:flex;gap:8px;padding:10px 14px;border-top:1px solid var(--line)">
      <textarea id="aiDraftIn" rows="2" placeholder="เล่าโครงการให้ฟัง เช่น อบรมผู้บริหาร 20 คน วันที่ 10-12 ก.พ. ..." maxlength="2000" style="flex:1;min-height:60px;border:1px solid var(--line);border-radius:12px;padding:9px 12px;font:inherit;line-height:1.5;resize:none"></textarea>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:center;justify-content:flex-end"><button type="button" id="aiDraftMic" class="ai-mic" title="พูดเป็นข้อความ (แปลงเสียงโดยเบราว์เซอร์)" aria-label="พูดเป็นข้อความ"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 15a4 4 0 0 0 4-4V6a4 4 0 0 0-8 0v5a4 4 0 0 0 4 4Zm-1 3.9V21H8.5a1 1 0 1 0 0 2h7a1 1 0 1 0 0-2H13v-2.1A7 7 0 0 0 19 12a1 1 0 1 0-2 0 5 5 0 0 1-10 0 1 1 0 1 0-2 0 7 7 0 0 0 6 6.9Z" transform="translate(0 -1.5)"/></svg></button>
      <button class="btn" style="padding:8px 16px">ส่ง</button></div>
    </form>
    <div id="aiDraftHint" style="font-size:10.5px;color:#8A8F98;padding:0 14px 8px">Enter = ส่ง · Shift+Enter = ขึ้นบรรทัดใหม่</div>
  </div>
</div>
<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>;
  var tog=document.getElementById('aiDraftToggle'),
      panel=document.getElementById('aiDraftPanel'),
      msgs=document.getElementById('aiDraftMsgs'),
      form=document.getElementById('aiDraftForm'),
      inp=document.getElementById('aiDraftIn'),
      chev=document.getElementById('aiDraftChev'),
      open=false, hist=[], sending=false;

  function togPanel(){
    open=!open;
    panel.style.display=open?'flex':'none';
    panel.style.flexDirection='column';
    chev.style.transform=open?'rotate(180deg)':'';
    if(open&&msgs.children.length===0){addMsg('assistant','สวัสดีค่ะ บอกรายละเอียดโครงการที่ต้องการจองได้เลยค่ะ เช่น ชื่อโครงการ ช่วงวันที่ จำนวนผู้เข้าพักชายหญิง หรือประเภทห้อง')}
  }
  tog.onclick=togPanel;

  function addMsg(role,text,isFill){
    var d=document.createElement('div');
    d.style.cssText='padding:8px 12px;border-radius:12px;max-width:88%;white-space:pre-wrap;line-height:1.6'
      +(role==='user'?';align-self:flex-end;background:var(--side);color:#fff;border-radius:12px 12px 4px 12px'
      :';align-self:flex-start;background:#F5F5F5;border-radius:12px 12px 12px 4px');
    d.textContent=text;
    msgs.appendChild(d);
    msgs.scrollTop=msgs.scrollHeight;
    return d;
  }

  function fillForm(f){
    ['name','start_date','end_date','meeting_room_id','male','female','rooms_trainee','rooms_speaker','rooms_committee','note'].forEach(function(k){
      if(f[k]===undefined||f[k]==='')return;
      var el=document.querySelector('#aiDraftWrap ~ form [name='+k+']');if(k==='meeting_room_id')f[k]=String(f[k]);
      if(el)el.value=f[k];
    });
    var fm=document.querySelector('#aiDraftWrap ~ form');if(fm)fm.scrollIntoView({behavior:'smooth',block:'start'});
  }

  form.onsubmit=function(e){
    e.preventDefault();
    var q=inp.value.trim();
    if(!q||sending)return;
    addMsg('user',q);
    hist.push({role:'user',content:q});
    inp.value='';
    sending=true;
    var wait=addMsg('assistant','กำลังคิด…');
    fetch(<?= json_encode(url('ai.php')) ?>,{
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body:JSON.stringify({message:q,history:hist,context:'draft_project'})
    }).then(function(r){return r.json()}).then(function(d){
      wait.remove();
      var reply=d.reply||'ขออภัย เกิดข้อผิดพลาด';
      addMsg('assistant',reply);
      hist.push({role:'assistant',content:reply});
      if(hist.length>20)hist=hist.slice(-20);
      if(d.fill){
        fillForm(d.fill);
        addMsg('assistant','✅ กรอกข้อมูลในฟอร์มด้านล่างให้แล้วค่ะ ตรวจสอบ แก้ไข หรือกดบันทึกได้เลยค่ะ');
      }
    }).catch(function(){wait.remove();addMsg('assistant','เกิดข้อผิดพลาด กรุณาลองใหม่')}).finally(function(){sending=false});
  };

  inp.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();form.requestSubmit()}});

  var mic=document.getElementById('aiDraftMic'),hint=document.getElementById('aiDraftHint'),HINT=hint.textContent,rec=null,listening=false,hintTm=null;
  function getSR(){return window.SpeechRecognition||window.webkitSpeechRecognition}
  function say(t,ms){clearTimeout(hintTm);hint.textContent=t;if(ms)hintTm=setTimeout(function(){hint.textContent=HINT},ms)}
  var want=false,silTm=null,gotText=false;
  function armSil(){clearTimeout(silTm);silTm=setTimeout(function(){want=false;if(rec){try{rec.stop()}catch(e){}}},5000)}
  function stopMic(){want=false;clearTimeout(silTm);if(rec){try{rec.stop()}catch(e){}}}
  function startMic(first){
    var SR=getSR();rec=new SR();rec.lang='th-TH';rec.interimResults=true;rec.continuous=true;rec.maxAlternatives=1;
    var base=inp.value?inp.value.replace(/\s+$/,'')+' ':'',finalText='',failed=false;
    if(first){want=true;gotText=false}
    rec.onstart=function(){listening=true;mic.classList.add('listening');say('🎤 กำลังฟัง... พูดต่อเนื่องได้เลย (คลิกไมค์เพื่อหยุด หรือเงียบ 5 วินาทีจะหยุดเอง)');if(first)armSil()};
    rec.onresult=function(e){
      var interim='';armSil();
      for(var i=e.resultIndex;i<e.results.length;i++){var t=e.results[i][0].transcript;if(e.results[i].isFinal)finalText+=t;else interim+=t}
      if(finalText||interim)gotText=true;
      inp.value=base+finalText+interim;
    };
    rec.onerror=function(e){
      failed=true;var er=e.error;if(er==='no-speech'&&want){failed=false;return}
      if(er==='not-allowed'||er==='service-not-allowed')uiAlert('ไม่ได้รับอนุญาตให้ใช้ไมโครโฟน — คลิกไอคอนกุญแจข้างที่อยู่เว็บ แล้วเลือกอนุญาตไมโครโฟนสำหรับเว็บนี้',{title:'ใช้ไมโครโฟนไม่ได้'});
      else if(er==='no-speech')say('ไม่ได้ยินเสียง ลองกดไมค์แล้วพูดใหม่',4000);
      else if(er==='audio-capture')say('ไม่พบไมโครโฟนในเครื่องนี้',5000);
      else if(er==='network')say('เชื่อมต่อบริการแปลงเสียงไม่ได้ (ต้องใช้อินเทอร์เน็ต)',5000);
      else if(er!=='aborted')say('แปลงเสียงไม่สำเร็จ ('+er+')',5000);
    };
    rec.onend=function(){
      rec=null;
      if(want&&!failed){startMic(false);return}
      want=false;clearTimeout(silTm);listening=false;mic.classList.remove('listening');
      if(!failed)say(gotText?'ตรวจข้อความก่อน แล้วกด Enter เพื่อส่ง':HINT,gotText?6000:0);
      inp.focus();inp.setSelectionRange(inp.value.length,inp.value.length);
    };
    try{rec.start()}catch(e){rec=null;say('เริ่มฟังไม่สำเร็จ ลองอีกครั้ง',3000)}
  }
  if(!getSR())mic.style.display='none';
  mic.onclick=function(){
    if(listening){stopMic();return}
    if(!window.isSecureContext){uiAlert('คำสั่งเสียงใช้ได้เฉพาะเมื่อเปิดเว็บผ่าน HTTPS หรือ localhost เท่านั้น',{title:'ใช้คำสั่งเสียงไม่ได้'});return}
    var ok=false;try{ok=localStorage.getItem('aiMicOk')==='1'}catch(e){}
    if(ok){startMic(true);return}
    uiConfirm('เบราว์เซอร์จะส่งเสียงของคุณไปแปลงเป็นข้อความที่ผู้ให้บริการของเบราว์เซอร์ (เช่น Google สำหรับ Chrome) ระบบนี้ไม่บันทึกเสียง — ใช้เฉพาะข้อความที่แปลงได้ ต้องการใช้ต่อหรือไม่?',{title:'ใช้ไมโครโฟน',danger:false,ok:'ใช้งาน'})
      .then(function(y){if(y){try{localStorage.setItem('aiMicOk','1')}catch(e){}startMic(true)}});
  };
  form.addEventListener('submit',function(){stopMic()},true);
})();
</script>
<?php endif; ?>
<form method="post" class="card" style="max-width:760px"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
  <label>ชื่อโครงการ</label><input type="text" name="name" value="<?= $v('name') ?>" required>
  <div class="row"><div><label>วันที่เริ่ม (เข้าพัก)</label><input type="date" name="start_date" value="<?= $v('start_date') ?>" required style="width:100%;border:1px solid var(--line);border-radius:8px;padding:9px 13px;font:inherit"></div>
  <div><label>วันที่สิ้นสุด (ออก)</label><input type="date" name="end_date" value="<?= $v('end_date') ?>" required style="width:100%;border:1px solid var(--line);border-radius:8px;padding:9px 13px;font:inherit"></div></div>
  <label>ห้องประชุมที่ต้องการ</label><select name="meeting_room_id"><option value="">— ไม่ใช้ห้องประชุม —</option>
    <?php foreach ($meeting as $m): ?><option value="<?= $m['id'] ?>" <?= ($_POST['meeting_room_id'] ?? $edit['meeting_room_id'] ?? '') == $m['id'] ? 'selected' : '' ?>><?= e($m['bn'] . ' · ' . $m['room_no']) ?></option><?php endforeach; ?></select>
  <div class="row"><div><label>ผู้เข้าพักชาย (คน)</label><input type="number" min="0" name="male" value="<?= $v('male', '0') ?>"></div><div><label>ผู้เข้าพักหญิง (คน)</label><input type="number" min="0" name="female" value="<?= $v('female', '0') ?>"></div></div>
  <div class="row" style="grid-template-columns:1fr 1fr 1fr"><div><label>ห้องผู้เข้าอบรม</label><input type="number" min="0" name="rooms_trainee" value="<?= $v('rooms_trainee', '0') ?>"></div>
  <div><label>ห้องวิทยากร</label><input type="number" min="0" name="rooms_speaker" value="<?= $v('rooms_speaker', '0') ?>"></div>
  <div><label>ห้องกรรมการดำเนินโครงการ</label><input type="number" min="0" name="rooms_committee" value="<?= $v('rooms_committee', '0') ?>"></div></div>
  <label>หมายเหตุ</label><input type="text" name="note" value="<?= $v('note') ?>">
  <div class="alert alert-warn" style="margin-top:14px">ระบุแยกชาย–หญิง ยกเว้นกรณีคู่สมรส ระบบจะตรวจสอบความซ้อนทับของห้องประชุมโดยอัตโนมัติหลังกดบันทึก</div>
  <button class="btn"><?= $edit ? 'บันทึกการแก้ไข' : 'บันทึกจองเบื้องต้น' ?></button>
</form>
<?php app_end();
