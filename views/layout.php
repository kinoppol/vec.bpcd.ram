<?php
declare(strict_types=1);

function page_head(string $title): void
{
    ?><!DOCTYPE html>
<html lang="th"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | ระบบบริหารที่พัก สสอ.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= (int)@filemtime(APP_ROOT . '/assets/css/app.css') ?>">
</head><body>
<?php
}

function page_foot(): void
{
    ?>
<dialog id="dlgConfirm" class="modal modal-sm" aria-labelledby="dcTitle">
  <div class="dc-body">
    <div class="dc-icon" id="dcIcon">!</div>
    <div>
      <div class="dc-title" id="dcTitle">ยืนยันการดำเนินการ</div>
      <div class="dc-msg" id="dcMsg"></div>
    </div>
  </div>
  <div class="modal-f"><button type="button" class="btn ghost" id="dcCancel">ยกเลิก</button><button type="button" class="btn" id="dcOk">ยืนยัน</button></div>
</dialog>
<script>
// ModalBox แทน confirm()/alert() ของเบราว์เซอร์: ใส่ data-confirm="ข้อความ" ที่ปุ่ม/ลิงก์/ฟอร์ม, หรือเรียก uiConfirm()/uiAlert() จากสคริปต์
(function(){
  var dlg=document.getElementById('dlgConfirm'),ok=document.getElementById('dcOk'),cancel=document.getElementById('dcCancel'),done=null;
  function show(msg,o){
    o=o||{};
    return new Promise(function(res){
      if(dlg.open)dlg.close();
      document.getElementById('dcTitle').textContent=o.title||(o.alert?'แจ้งเตือน':'ยืนยันการดำเนินการ');
      document.getElementById('dcMsg').textContent=msg;
      var danger=o.danger!==false&&!o.alert;
      var ic=document.getElementById('dcIcon');ic.textContent=o.alert?'i':(danger?'!':'?');ic.className='dc-icon'+(danger?' danger':'');
      ok.textContent=o.ok||(o.alert?'ตกลง':(/ลบ/.test(msg)?'ลบ':'ยืนยัน'));
      ok.className='btn'+(danger?' danger':'');
      cancel.style.display=o.alert?'none':'';
      done=res;dlg.showModal();ok.focus();
    });
  }
  function close(v){if(dlg.open)dlg.close();var r=done;done=null;if(r)r(v)}
  ok.onclick=function(){close(true)};cancel.onclick=function(){close(false)};
  dlg.addEventListener('cancel',function(e){e.preventDefault();close(false)});      // ปุ่ม Esc
  dlg.addEventListener('click',function(e){if(e.target===dlg)close(false)});        // คลิกพื้นหลัง
  window.uiConfirm=function(m,o){return show(m,o)};
  window.uiAlert=function(m,o){o=o||{};o.alert=true;return show(m,o)};

  // ปุ่ม/ลิงก์ที่มี data-confirm
  document.addEventListener('click',function(e){
    var el=e.target.closest('button[data-confirm],a[data-confirm],input[data-confirm]');
    if(!el||el.disabled)return;
    if(el.dataset.okd){delete el.dataset.okd;return}
    e.preventDefault();e.stopPropagation();
    show(el.dataset.confirm,{danger:el.dataset.danger!=='0',ok:el.dataset.ok}).then(function(y){if(y){el.dataset.okd='1';el.click()}});
  },true);
  // ฟอร์มที่มี data-confirm
  document.addEventListener('submit',function(e){
    var f=e.target;if(!f.dataset||!f.dataset.confirm)return;
    if(f.dataset.okd){delete f.dataset.okd;return}
    e.preventDefault();
    var sub=e.submitter;
    show(f.dataset.confirm,{danger:f.dataset.danger!=='0',ok:f.dataset.ok}).then(function(y){if(y){f.dataset.okd='1';f.requestSubmit(sub||undefined)}});
  },true);
})();
</script>
</body></html>
<?php
}

function app_start(string $title, array $user, string $active): void
{
    $r = $user['role'];
    $owner = $r === 'owner' || $r === 'admin';
    $care = $r === 'caretaker' || $r === 'admin';
    // เมนูจัดกลุ่มตามงาน: [ชื่อกลุ่ม|null (ไม่มีกลุ่ม), [[href, label, key, badge?], ...]]
    $groups = [
        [null, [['index.php', 'ภาพรวมระบบ', 'home']]],
        ['การจอง', array_merge(
            $owner ? [['book.php', 'จองห้องพักและห้องประชุม', 'book'], ['my_bookings.php', 'สถานะการจอง', 'my']] : [],
            $care ? [['requests.php', 'คำขอจองห้อง', 'requests']] : []
        )],
        ['งานผู้เข้าพัก', $care ? [['guests.php', 'จัดผู้เข้าพัก', 'guests'], ['checkin.php', 'Check-in / Check-out', 'checkin']] : []],
        ['อาคารและห้อง', $care ? [['rooms.php', 'สถานะห้องพัก / ประเภทห้อง', 'rooms'], ['facilities.php', 'นำเข้า / ส่งออกข้อมูล (ZIP)', 'facilities']] : []],
        ['รายงาน', $r !== 'owner' ? [['reports.php', 'รายงาน / Export', 'reports']] : []],
        ['ผู้ดูแลระบบ', $r === 'admin' ? [
            ['admin/users.php', 'ผู้ใช้งานระบบ', 'users'],
            ['admin/settings.php', 'ตั้งค่าการแจ้งเตือน', 'settings'],
            ['admin/ai.php', 'ผู้ช่วย AI (API)', 'ai'],
            ['admin/audit.php', 'บันทึกการใช้งาน', 'audit'],
            ['admin/migrations.php', 'จัดการฐานข้อมูล (Migrations)', 'migrations', pending_migrations()],
        ] : []],
    ];
    $groups = array_filter($groups, fn($g) => $g[1]);
    page_head($title);
    ?>
<div class="app">
  <aside class="side">
    <div class="logo"><img src="<?= e(url('assets/vec-logo.png')) ?>" alt=""><div>ระบบบริหารที่พัก สสอ.<br><span style="font-weight:400;color:#B79FA3">สอศ.</span></div></div>
    <div class="role"><?= e(Auth::ROLES[$user['role']] ?? $user['role']) ?></div>
    <nav>
      <?php foreach ($groups as $gi => [$glabel, $items]):
          $links = '';
          $has = false;
          $badges = 0;
          foreach ($items as $it) {
              [$href, $label, $key] = $it;
              $badge = (int)($it[3] ?? 0);
              $has = $has || $key === $active;
              $badges += $badge;
              $links .= '<a href="' . e(url($href)) . '" class="' . ($key === $active ? 'on' : '') . '">' . e($label)
                  . ($badge ? '<span class="pill" title="รอดำเนินการ">' . $badge . '</span>' : '') . '</a>';
          }
          if ($glabel === null) { echo $links; continue; } ?>
        <details class="ngrp" data-g="<?= $gi ?>" <?= $has ? 'open data-active' : '' ?>>
          <summary><?= e($glabel) ?><?php if ($badges): ?><span class="pill"><?= $badges ?></span><?php endif; ?></summary>
          <div class="ngrp-b"><?= $links ?></div>
        </details>
      <?php endforeach; ?>
    </nav>
    <script>
    // จำสถานะยุบ/ขยายของแต่ละกลุ่มเมนู (กลุ่มของหน้าที่เปิดอยู่ขยายเสมอ)
    (function(){var k='navOpen',s={};try{s=JSON.parse(localStorage.getItem(k)||'{}')}catch(e){}
      document.querySelectorAll('.ngrp').forEach(function(d){
        if(!d.hasAttribute('data-active')&&s[d.dataset.g]!==undefined)d.open=!!s[d.dataset.g];
        else if(!d.hasAttribute('data-active')&&s[d.dataset.g]===undefined)d.open=true;
        d.addEventListener('toggle',function(){s[d.dataset.g]=d.open?1:0;try{localStorage.setItem(k,JSON.stringify(s))}catch(e){}});
      });})();
    </script>
    <a class="out" href="<?= e(url('logout.php')) ?>">ออกจากระบบ</a>
  </aside>
  <div class="main">
    <div class="top"><b><?= e($title) ?></b>
      <div style="display:flex;gap:10px;align-items:center"><span style="font-size:12px;color:#8A8F98"><?= e($user['full_name']) ?></span>
      <div class="avatar"><?= e(mb_substr($user['full_name'], 0, 1)) ?></div></div></div>
    <div class="content">
      <?= render_flash() ?>
      <?php if ($user['role'] === 'admin' && ($np = pending_migrations()) > 0): ?>
        <div class="alert alert-warn">มี <?= $np ?> การปรับปรุงฐานข้อมูลที่ยังไม่ได้รัน — บางฟังก์ชันอาจทำงานไม่ครบ <a href="<?= e(url('admin/migrations.php')) ?>">ไปที่เมนู Migrations</a></div>
      <?php endif; ?>
<?php
}

function app_end(): void
{
    echo "</div></div></div>";
    if (setting('ai_enabled') === '1') {
        ai_widget();
    }
    page_foot();
}

function ai_widget(): void
{
    $cost = setting('ai_cost', '5');
    $profs = ai_profiles(true);
    $auto = setting('ai_select_mode', 'user') === 'auto';
    $defId = ai_default_profile_id();
    // คีย์เก็บประวัติแชท: ผูกกับผู้ใช้และเซสชันปัจจุบัน — ออกจากระบบแล้วเข้าใหม่ (เซสชันใหม่) จะไม่เห็นประวัติเดิม
    $storeKey = 'aiChat:' . (int)($_SESSION['uid'] ?? 0) . ':' . substr(hash('sha256', csrf_token()), 0, 10);
    ?>
<div id="ai" style="position:fixed;right:24px;bottom:24px;z-index:50;display:flex;flex-direction:column;align-items:flex-end;gap:10px">
  <div id="aibox" style="display:none;width:420px;height:600px;max-height:85vh;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,.25);flex-direction:column;overflow:hidden">
    <div style="background:var(--side);color:#fff;padding:11px 16px;font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:10px"><span><b>ผู้ช่วย AI สสอ.</b> <button type="button" id="aiclr" title="ล้างการสนทนา" style="border:0;background:none;color:#B79FA3;cursor:pointer;font:inherit;font-size:11px;text-decoration:underline">ล้าง</button></span>
      <?php if ($auto): ?><span style="font-size:11px;color:#D9CFC8" title="ระบบเลือก API ให้ตามความยาวของคำถาม">เลือกอัตโนมัติ</span>
      <?php elseif (count($profs) > 1): ?><select id="aiprof" title="เลือก API ปลายทาง" style="width:auto;max-width:190px;padding:4px 8px;font-size:12px;border-radius:8px"><?php foreach ($profs as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $defId ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><?php endif; ?></div>
    <div id="aimsg" style="white-space:normal;flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:9px;font-size:12.5px"></div>
    <div id="aihint" style="font-size:10.5px;color:#8A8F98;padding:0 14px 6px">Enter = ส่ง · Shift+Enter = ขึ้นบรรทัดใหม่ · แต่ละคำถามบันทึกค่าใช้จ่ายประมาณ <?= e($cost) ?> บาท</div>
    <form id="aiform" style="display:flex;gap:8px;padding:10px 14px;border-top:1px solid var(--line)"><textarea id="aiin" rows="3" placeholder="พิมพ์คำถามหรือคำสั่ง..." maxlength="4000" style="flex:1;min-height:76px;max-height:220px;border:1px solid var(--line);border-radius:14px;padding:10px 13px;font:inherit;line-height:1.5;resize:none;overflow-y:auto"></textarea><div class="ai-actions"><button type="button" id="aimic" class="ai-mic" title="พูดเป็นคำสั่งเสียง (แปลงเป็นข้อความโดยเบราว์เซอร์)" aria-label="พูดเป็นคำสั่งเสียง"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 15a4 4 0 0 0 4-4V6a4 4 0 0 0-8 0v5a4 4 0 0 0 4 4Zm-1 3.9V21H8.5a1 1 0 1 0 0 2h7a1 1 0 1 0 0-2H13v-2.1A7 7 0 0 0 19 12a1 1 0 1 0-2 0 5 5 0 0 1-10 0 1 1 0 1 0-2 0 7 7 0 0 0 6 6.9Z" transform="translate(0 -1.5)"/></svg></button><button class="btn ai-send">ส่ง</button></div></form>
  </div>
  <button id="aibtn" class="ai-fab" type="button" title="ถามผู้ช่วย AI" aria-label="เปิดผู้ช่วย AI">
    <svg viewBox="0 0 64 56" width="40" height="35" aria-hidden="true">
      <line x1="32" y1="9" x2="32" y2="16" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>
      <circle class="ai-ant" cx="32" cy="6.5" r="4" fill="#FFD3D9"/>
      <rect x="3" y="26" width="7" height="12" rx="3.5" fill="#F5C9CF"/><rect x="54" y="26" width="7" height="12" rx="3.5" fill="#F5C9CF"/>
      <rect x="9" y="15" width="46" height="36" rx="16" fill="#fff"/>
      <g class="ai-eyes"><ellipse cx="24" cy="31" rx="4.2" ry="5.2" fill="#4E1119"/><ellipse cx="40" cy="31" rx="4.2" ry="5.2" fill="#4E1119"/>
        <circle cx="25.4" cy="29.2" r="1.5" fill="#fff"/><circle cx="41.4" cy="29.2" r="1.5" fill="#fff"/></g>
      <circle cx="16.5" cy="39" r="3.4" fill="#F4A7B0" opacity=".8"/><circle cx="47.5" cy="39" r="3.4" fill="#F4A7B0" opacity=".8"/>
      <path d="M27.5 39.5q4.5 4.6 9 0" stroke="#4E1119" stroke-width="2.4" fill="none" stroke-linecap="round"/>
    </svg>
  </button>
</div>
<script>
(function(){
  var KEY=<?= json_encode($storeKey) ?>, GREET='สวัสดีค่ะ สอบถามข้อมูลห้องพัก โครงการ หรือสรุปสถานการณ์ได้เลยค่ะ';
  var $=function(i){return document.getElementById(i)}, box=$('aibox'), msg=$('aimsg'), inp=$('aiin'), prof=$('aiprof');
  // ประวัติแชทเก็บใน sessionStorage ของแท็บนี้ (ปิดแท็บ/ออกจากระบบแล้วหาย) ให้คงอยู่เมื่อเปลี่ยนหน้าไปมา
  var st; try{st=JSON.parse(sessionStorage.getItem(KEY)||'null')}catch(e){st=null}
  if(!st||!Array.isArray(st.msgs))st={msgs:[],open:false,draft:''};
  function save(){try{if(st.msgs.length>80)st.msgs=st.msgs.slice(-80);sessionStorage.setItem(KEY,JSON.stringify(st))}catch(e){}}

  if(prof){try{var sv=localStorage.getItem('aiProf');if(sv&&[].some.call(prof.options,function(o){return o.value===sv}))prof.value=sv}catch(e){}prof.onchange=function(){try{localStorage.setItem('aiProf',prof.value)}catch(e){}}}

  // วาดเนื้อหาของฟองข้อความจากข้อมูล rec
  function fill(el,rec){
    if(el._tm){clearInterval(el._tm);el._tm=null}
    el.innerHTML='';
    if(rec.loading){ // กำลังรอคำตอบ: จุดกระโดดเป็นจังหวะ + ตัวนับเวลา ให้รู้ว่ายังทำงานอยู่
      var lb=(rec.t||'กำลังคิด').replace(/[.…]+$/,''),w=document.createElement('span');w.className='ai-think';
      w.innerHTML='<span class="ai-think-t"></span><i></i><i></i><i></i><small></small>';
      w.firstChild.textContent=lb;el.appendChild(w);
      var t0=rec.ts||(rec.ts=Date.now()),sm=w.querySelector('small');
      el._tm=setInterval(function(){var n=Math.floor((Date.now()-t0)/1000);sm.textContent=n>=5?' · '+n+' วินาที':''},1000);
      return;
    }
    el.appendChild(document.createTextNode(rec.t||''));
    if(rec.via){var v=document.createElement('div');v.style.cssText='font-size:10px;color:#8A8F98;margin-top:4px';v.textContent='ตอบโดย: '+rec.via;el.appendChild(v)}
    if(rec.pending&&rec.pending.exp>Date.now())el.appendChild(pendingBox(el,rec));
  }
  function pendingBox(el,rec){
    var b=document.createElement('div');b.style.cssText='margin-top:8px;padding:8px;border:1px solid #E6DED8;border-radius:8px;background:#fff';
    b.innerHTML='<b style="display:block;margin-bottom:6px">รอยืนยัน:</b>';
    var t=document.createElement('div');t.style.cssText='white-space:pre-wrap;max-height:220px;overflow-y:auto;font-size:12px';t.textContent=rec.pending.summary;b.appendChild(t);
    function btn(l,k,pri){var x=document.createElement('button');x.type='button';x.textContent=l;x.className='btn'+(pri?'':' ghost');x.style.cssText='padding:6px 14px;margin:8px 6px 0 0;font-size:12px';
      x.onclick=function(){var o={};o[k]=rec.pending.id;rec.pending=null;fill(el,rec);save();send(o,add('กำลังดำเนินการ...',false,true))};return x}
    b.appendChild(btn('ยืนยันดำเนินการ','confirm',true));b.appendChild(btn('ยกเลิก','cancel',false));return b;
  }
  function render(rec){
    var d=document.createElement('div');
    d.style.cssText='max-width:85%;padding:8px 12px;border-radius:12px;line-height:1.5;white-space:pre-wrap;align-self:'+(rec.me?'flex-end':'flex-start')+';background:'+(rec.me?'#7A1E2C':'#F7F3EE')+';color:'+(rec.me?'#fff':'#1F1B1A');
    fill(d,rec);msg.appendChild(d);return d;
  }
  function add(text,me,loading){var rec={t:text,me:!!me};if(loading)rec.loading=true;st.msgs.push(rec);var el=render(rec);msg.scrollTop=msg.scrollHeight;save();return{rec:rec,el:el}}

  // โหลดประวัติเดิม: ข้อความที่รอคำตอบค้างอยู่ (หน้าถูกเปลี่ยนก่อนตอบเสร็จ) ให้แจ้งเตือน
  st.msgs.forEach(function(r){if(r.loading){r.loading=false;r.t='⚠ หน้าถูกเปลี่ยนก่อนได้รับคำตอบ — ลองส่งคำถามใหม่อีกครั้ง'}});
  if(!st.msgs.length)st.msgs.push({t:GREET,me:false});
  st.msgs.forEach(render);
  inp.value=st.draft||'';
  function setOpen(o){if(!o)stopMic();st.open=o;box.style.display=o?'flex':'none';save();if(o){msg.scrollTop=msg.scrollHeight;inp.focus()}}
  if(st.open){box.style.display='flex';msg.scrollTop=msg.scrollHeight}
  $('aibtn').onclick=function(){setOpen(box.style.display!=='flex')};
  $('aiclr').onclick=function(){uiConfirm('ล้างประวัติการสนทนาทั้งหมด?',{ok:'ล้าง'}).then(function(y){if(!y)return;st.msgs=[{t:GREET,me:false}];msg.innerHTML='';st.msgs.forEach(render);save()})};

  function grow(){inp.style.height='auto';inp.style.height=Math.max(76,Math.min(inp.scrollHeight,220))+'px'}
  grow();
  inp.oninput=function(){grow();st.draft=inp.value;save()};
  inp.onkeydown=function(e){if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();$('aiform').requestSubmit()}};
  // ---- คำสั่งเสียง: ให้เบราว์เซอร์แปลงเสียงเป็นข้อความ (Web Speech API) แล้วใส่ในช่องพิมพ์ให้ตรวจก่อนกดส่ง ----
  var mic=$('aimic'),hint=$('aihint'),HINT=hint.textContent,rec=null,listening=false,hintTm=null;
  function getSR(){return window.SpeechRecognition||window.webkitSpeechRecognition}
  function say(t,ms){clearTimeout(hintTm);hint.textContent=t;if(ms)hintTm=setTimeout(function(){hint.textContent=HINT},ms)}
  var want=false,silTm=null,gotText=false;
  function armSil(){clearTimeout(silTm);silTm=setTimeout(function(){want=false;stopMic()},5000)} // เงียบเกิน 5 วินาที → หยุดเอง
  function stopMic(){want=false;clearTimeout(silTm);if(rec){try{rec.stop()}catch(e){}}}
  function startMic(first){
    var SR=getSR();rec=new SR();rec.lang='th-TH';rec.interimResults=true;rec.continuous=true;rec.maxAlternatives=1;
    var base=inp.value?inp.value.replace(/\s+$/,'')+' ':'',finalText='',failed=false;
    if(first){want=true;gotText=false}
    rec.onstart=function(){listening=true;mic.classList.add('listening');mic.title='กำลังฟัง — คลิกเพื่อหยุด';say('🎤 กำลังฟัง... พูดต่อเนื่องได้เลย (คลิกไมค์เพื่อหยุด หรือเงียบ 5 วินาทีจะหยุดเอง)');if(first)armSil()};
    rec.onresult=function(e){
      var interim='';armSil();
      for(var i=e.resultIndex;i<e.results.length;i++){var t=e.results[i][0].transcript;if(e.results[i].isFinal)finalText+=t;else interim+=t}
      if(finalText||interim)gotText=true;
      inp.value=base+finalText+interim;grow();st.draft=inp.value;save();
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
      if(want&&!failed){startMic(false);return} // เบราว์เซอร์ตัดการฟังเอง แต่ยังไม่ครบ 5 วินาทีเงียบ → เริ่มฟังต่อ
      want=false;clearTimeout(silTm);listening=false;mic.classList.remove('listening');mic.title='พูดเป็นคำสั่งเสียง (แปลงเป็นข้อความโดยเบราว์เซอร์)';
      if(!failed)say(gotText?'ตรวจข้อความก่อน แล้วกด Enter เพื่อส่ง':HINT,gotText?6000:0);
      inp.focus();inp.setSelectionRange(inp.value.length,inp.value.length);
    };
    try{rec.start()}catch(e){rec=null;say('เริ่มฟังไม่สำเร็จ ลองอีกครั้ง',3000)}
  }
  if(!getSR()){mic.style.display='none'} // เบราว์เซอร์ไม่รองรับ (เช่น Firefox) — ซ่อนปุ่ม ใช้พิมพ์ตามปกติ
  mic.onclick=function(){
    if(listening){stopMic();return}
    if(!window.isSecureContext){uiAlert('คำสั่งเสียงใช้ได้เฉพาะเมื่อเปิดเว็บผ่าน HTTPS หรือ localhost เท่านั้น',{title:'ใช้คำสั่งเสียงไม่ได้'});return}
    var ok=false;try{ok=localStorage.getItem('aiMicOk')==='1'}catch(e){}
    if(ok){startMic(true);return}
    uiConfirm('เบราว์เซอร์จะส่งเสียงของคุณไปแปลงเป็นข้อความที่ผู้ให้บริการของเบราว์เซอร์ (เช่น Google สำหรับ Chrome) ระบบนี้ไม่บันทึกเสียง — ใช้เฉพาะข้อความที่แปลงได้ ต้องการใช้ต่อหรือไม่?',{title:'ใช้ไมโครโฟน',danger:false,ok:'ใช้งาน'})
      .then(function(y){if(y){try{localStorage.setItem('aiMicOk','1')}catch(e){}startMic(true)}});
  };

  // ประวัติที่ส่งให้โมเดล: 6 ข้อความล่าสุดก่อนคำถามนี้ (ตัดคำทักทาย ข้อความรอ และข้อความแสดงข้อผิดพลาดออก)
  function history(){
    return st.msgs.filter(function(r){return !r.loading&&r.t&&r.t!==GREET&&!/^(⚠|❌|เชื่อมต่อไม่สำเร็จ|เซิร์ฟเวอร์ตอบกลับ)/.test(r.t)})
      .slice(-6).map(function(r){return{role:r.me?'user':'assistant',content:String(r.t).slice(0,1200)}});
  }
  $('aiform').onsubmit=function(e){e.preventDefault();stopMic();var q=inp.value.trim();if(!q)return;inp.value='';st.draft='';grow();
    var h=history();add(q,true);
    send({message:q,history:h,profile:prof?+prof.value:0},add('กำลังคิด...',false,true))};

  function send(body,item){
    return fetch(<?= json_encode(url('ai.php')) ?>,{method:'POST',headers:{'X-CSRF-Token':<?= json_encode(csrf_token()) ?>},body:JSON.stringify(body)})
    .then(function(r){return r.text().then(function(t){try{return JSON.parse(t)}catch(e){return{reply:'เซิร์ฟเวอร์ตอบกลับผิดปกติ (HTTP '+r.status+') — ตรวจสอบ error log ของเซิร์ฟเวอร์'}}})})
    .catch(function(){return{reply:'เชื่อมต่อไม่สำเร็จ'}})
    .then(function(j){
      var rec=item.rec;delete rec.loading;delete rec.ts;rec.t=j.reply||'';rec.via=j.via||null;
      rec.pending=j.pending?{id:j.pending.id,summary:j.pending.summary,exp:Date.now()+9*60000}:null; // คำสั่งรอยืนยันหมดอายุที่เซิร์ฟเวอร์ 10 นาที
      fill(item.el,rec);msg.scrollTop=msg.scrollHeight;save();
    });
  }
})();
</script>
<?php
}
