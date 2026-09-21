<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/views/layout.php';

$me = Auth::require('admin');
$db = Db::pdo();

if (post_action() === 'save') {
    save_setting('mail_enabled', empty($_POST['mail_enabled']) ? '0' : '1');
    save_setting('mail_from', trim($_POST['mail_from'] ?? ''));
    save_setting('line_target', trim($_POST['line_target'] ?? ''));
    // ความลับ: เว้นว่าง = คงค่าเดิม
    if (trim($_POST['line_token'] ?? '') !== '') {
        save_setting('line_token', trim($_POST['line_token']));
    }
    audit('แก้ไขการตั้งค่าการแจ้งเตือน');
    flash('success', 'บันทึกการตั้งค่าแล้ว');
    redirect('admin/settings.php');
}

$mask = fn(string $v) => $v === '' ? 'ยังไม่ได้ตั้งค่า' : '••••' . substr($v, -4);
$notifs = $db->query('SELECT * FROM notifications ORDER BY id DESC LIMIT 20')->fetchAll();

app_start('ตั้งค่าการแจ้งเตือน', $me, 'settings');
?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<h2 style="margin-top:0">การแจ้งเตือน</h2>
<label style="display:flex;gap:8px;align-items:center;color:#1F1B1A"><input type="checkbox" name="mail_enabled" value="1" <?= setting('mail_enabled') === '1' ? 'checked' : '' ?>> เปิดส่งอีเมล (ต้องตั้งค่า SMTP/sendmail ใน php.ini ของเซิร์ฟเวอร์)</label>
<div class="row"><div><label>อีเมลผู้ส่ง (From)</label><input type="text" name="mail_from" value="<?= e(setting('mail_from')) ?>"></div>
<div><label>LINE Messaging API — Channel access token (<?= e($mask(setting('line_token'))) ?>)</label><input type="password" name="line_token" placeholder="เว้นว่าง = ไม่เปลี่ยน" autocomplete="new-password"></div>
<div><label>LINE — User ID / Group ID ปลายทางของเจ้าหน้าที่</label><input type="text" name="line_target" value="<?= e(setting('line_target')) ?>"></div></div>
<p><button class="btn">บันทึก</button> <span style="font-size:12px;color:#8A8F98;margin-left:8px">ตั้งค่าผู้ช่วย AI ได้ที่เมนู "ผู้ช่วย AI (API)"</span></p></form>
<div class="card"><b>ประวัติการแจ้งเตือนล่าสุด</b>
<table style="margin-top:8px"><tr><th>เวลา</th><th>ช่องทาง</th><th>ผู้รับ</th><th>หัวข้อ</th><th>ผล</th></tr>
<?php foreach ($notifs as $n): ?><tr><td><?= e($n['created_at']) ?></td><td><?= e($n['channel']) ?></td><td><?= e($n['recipient']) ?></td><td><?= e($n['subject']) ?></td>
<td><span class="badge <?= $n['status'] === 'sent' ? 'ok' : ($n['status'] === 'failed' ? 'bad' : 'pend') ?>"><?= e($n['status']) ?></span> <span style="font-size:11px;color:#8A8F98"><?= e($n['error']) ?></span></td></tr><?php endforeach; ?>
<?php if (!$notifs): ?><tr><td colspan="5">ยังไม่มีการแจ้งเตือน</td></tr><?php endif; ?></table></div>
<?php app_end();
