<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

if (!is_installed()) {
    redirect('install.php');
}
if (Auth::user()) {
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (Auth::attempt(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
        redirect('index.php');
    }
    flash('error', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
}

page_head('เข้าสู่ระบบ');
?>
<div class="center-page"><form class="login-box" method="post">
  <div class="brand"><img src="<?= e(url('assets/vec-logo.png')) ?>" alt=""><small>สำนักพัฒนาสมรรถนะครูและบุคลากรอาชีวศึกษา (สสอ.)</small><b>ระบบบริหารห้องพักและห้องประชุม</b></div>
  <?= render_flash() . csrf_field() ?>
  <label>ชื่อผู้ใช้</label><input type="text" name="username" required autofocus autocomplete="username">
  <label>รหัสผ่าน</label><input type="password" name="password" required autocomplete="current-password">
  <p><button class="btn" style="width:100%;margin-top:14px">เข้าสู่ระบบ</button></p>
</form></div>
<?php page_foot();
