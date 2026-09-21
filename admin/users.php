<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/views/layout.php';

$me = Auth::require('admin');
$db = Db::pdo();

try {
    switch (post_action()) {
        case 'add':
            $un = trim($_POST['username'] ?? '');
            $pw = (string)($_POST['password'] ?? '');
            $role = $_POST['role'] ?? '';
            if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $un) || mb_strlen($pw) < 8 || !isset(Auth::ROLES[$role]) || trim($_POST['full_name'] ?? '') === '') {
                throw new RuntimeException('กรอกข้อมูลไม่ครบ/ไม่ถูกต้อง (ชื่อผู้ใช้ 3-60 ตัว, รหัสผ่านอย่างน้อย 8 ตัว)');
            }
            $db->prepare('INSERT INTO users (username,password_hash,full_name,email,dept,role) VALUES (?,?,?,?,?,?)')
                ->execute([$un, password_hash($pw, PASSWORD_DEFAULT), trim($_POST['full_name']), trim($_POST['email'] ?? '') ?: null, trim($_POST['dept'] ?? '') ?: null, $role]);
            audit("เพิ่มผู้ใช้ $un ($role)");
            flash('success', 'เพิ่มผู้ใช้เรียบร้อย');
            redirect('admin/users.php');
        case 'toggle':
            $id = (int)$_POST['id'];
            if ($id === (int)$me['id']) {
                throw new RuntimeException('ไม่สามารถปิดบัญชีตัวเองได้');
            }
            $db->prepare('UPDATE users SET active=1-active WHERE id=?')->execute([$id]);
            audit("สลับสถานะผู้ใช้ #$id");
            redirect('admin/users.php');
        case 'reset':
            $pw = (string)($_POST['password'] ?? '');
            if (mb_strlen($pw) < 8) {
                throw new RuntimeException('รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัว');
            }
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$_POST['id']]);
            audit('รีเซ็ตรหัสผ่านผู้ใช้ #' . (int)$_POST['id']);
            flash('success', 'เปลี่ยนรหัสผ่านเรียบร้อย');
            redirect('admin/users.php');
    }
} catch (Throwable $ex) {
    flash('error', str_contains($ex->getMessage(), 'Duplicate') ? 'ชื่อผู้ใช้นี้ถูกใช้แล้ว' : $ex->getMessage());
}

$users = $db->query('SELECT * FROM users ORDER BY id')->fetchAll();
app_start('ผู้ใช้งานระบบ', $me, 'users');
?>
<div class="card"><h2 style="margin-top:0">เพิ่มผู้ใช้งาน</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
<div class="row"><div><label>ชื่อผู้ใช้</label><input type="text" name="username" required></div>
<div><label>รหัสผ่าน (8 ตัวขึ้นไป)</label><input type="password" name="password" required autocomplete="new-password"></div>
<div><label>ชื่อ–สกุล</label><input type="text" name="full_name" required></div>
<div><label>อีเมล (สำหรับแจ้งเตือน)</label><input type="text" name="email"></div>
<div><label>หน่วยงาน/กลุ่มงาน</label><input type="text" name="dept"></div>
<div><label>บทบาท</label><select name="role"><?php foreach (Auth::ROLES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div></div>
<p><button class="btn">เพิ่มผู้ใช้</button></p></form></div>
<div class="card" style="padding:0;overflow:hidden"><table><tr><th>ชื่อผู้ใช้</th><th>ชื่อ–สกุล</th><th>หน่วยงาน</th><th>บทบาท</th><th>สถานะ</th><th>จัดการ</th></tr>
<?php foreach ($users as $u): ?><tr><td><code><?= e($u['username']) ?></code></td><td><?= e($u['full_name']) ?></td><td><?= e($u['dept']) ?></td>
<td><span class="badge" style="background:#F5E7E9;color:#7A1E2C"><?= e(Auth::ROLES[$u['role']]) ?></span></td>
<td><span class="badge <?= $u['active'] ? 'ok' : 'bad' ?>"><?= $u['active'] ? 'ใช้งาน' : 'ปิดใช้งาน' ?></span></td>
<td><div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn ghost">สลับสถานะ</button></form>
<form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="password" name="password" placeholder="รหัสผ่านใหม่" style="width:140px" autocomplete="new-password"><button class="btn ghost">รีเซ็ต</button></form></div></td></tr>
<?php endforeach; ?></table></div>
<?php app_end();
