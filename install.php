<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/views/layout.php';

// ---------- การตรวจสอบระบบ ----------
function system_checks(): array
{
    $checks = [];
    $add = function (string $label, bool $ok, string $detail = '', bool $required = true) use (&$checks) {
        $checks[] = compact('label', 'ok', 'detail', 'required');
    };
    $add('PHP 8.0 ขึ้นไป', version_compare(PHP_VERSION, '8.0.0', '>='), 'พบ ' . PHP_VERSION);
    foreach (['pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL driver (MariaDB)', 'mbstring' => 'mbstring', 'json' => 'json', 'session' => 'session', 'ctype' => 'ctype'] as $ext => $name) {
        $add("เพกเกจ PHP: $name", extension_loaded($ext), extension_loaded($ext) ? 'พร้อมใช้งาน' : "ไม่พบ extension $ext");
    }
    foreach (['config' => 'config/', 'storage' => 'storage/'] as $dir => $label) {
        $p = APP_ROOT . '/' . $dir;
        $add("สิทธิ์เขียนโฟลเดอร์ $label", is_dir($p) && is_writable($p), is_writable($p) ? 'เขียนได้' : 'เขียนไม่ได้ กรุณาปรับสิทธิ์โฟลเดอร์');
    }
    if (is_file(CONFIG_FILE)) {
        $add('สิทธิ์เขียนไฟล์ config/config.php', is_writable(CONFIG_FILE), is_writable(CONFIG_FILE) ? 'เขียนได้' : 'เขียนไม่ได้');
    }
    $mig = APP_ROOT . '/migrations';
    $add('สิทธิ์อ่านโฟลเดอร์ migrations/', is_dir($mig) && is_readable($mig), is_readable($mig) ? 'อ่านได้' : 'อ่านไม่ได้');
    return $checks;
}

function mariadb_version(PDO $pdo): ?string
{
    $v = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $v, $m) || preg_match('/^5\.5\.5-(\d+\.\d+\.\d+)/', $v, $m)) {
        return $m[1];
    }
    return null;
}

function drop_all_tables(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string)$t) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function write_config(array $db): void
{
    $php = "<?php\n// สร้างโดย install.php เมื่อ " . date('Y-m-d H:i:s') . "\nreturn " . var_export(['db' => $db], true) . ";\n";
    $tmp = CONFIG_FILE . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, CONFIG_FILE)) {
        throw new RuntimeException('เขียนไฟล์ config/config.php ไม่สำเร็จ');
    }
}

// ---------- ควบคุมการเข้าถึง (กรณีติดตั้งซ้ำ) ----------
$existing = app_config();
$reinstall = $existing !== null;
if ($reinstall) {
    $u = Auth::user();
    if (!$u || $u['role'] !== 'admin') {
        page_head('ติดตั้งระบบ');
        echo '<div class="center-page"><div class="login-box"><div class="alert alert-warn">ระบบถูกติดตั้งแล้ว การติดตั้งซ้ำต้องเข้าสู่ระบบด้วยบัญชีผู้ดูแลระบบก่อน</div>'
            . '<p><a class="btn" href="' . e(url('login.php')) . '">เข้าสู่ระบบ</a></p>'
            . '<p style="font-size:12px;color:#8A8F98">หากเข้าสู่ระบบไม่ได้ (เช่น ฐานข้อมูลเสีย) ให้ลบไฟล์ <code>config/config.php</code> และ <code>storage/installed.lock</code> ด้วยตนเองเพื่อเริ่มติดตั้งใหม่</p></div></div>';
        page_foot();
        exit;
    }
}

$checks = system_checks();
$canInstall = !array_filter($checks, fn($c) => $c['required'] && !$c['ok']);

$old = [
    'host' => $existing['db']['host'] ?? 'localhost',
    'port' => (string)($existing['db']['port'] ?? 3306),
    'name' => $existing['db']['name'] ?? 'vec_bpcd_ram',
    'user' => $existing['db']['user'] ?? 'root',
    'username' => 'admin',
    'full_name' => 'ผู้ดูแลระบบ',
];
$done = false;

// ---------- ประมวลผลการติดตั้ง ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canInstall) {
    csrf_check();
    $in = $_POST;
    foreach (['host', 'port', 'name', 'user', 'username', 'full_name'] as $k) {
        $old[$k] = trim((string)($in[$k] ?? ''));
    }
    $dbPass = (string)($in['pass'] ?? '');
    if ($dbPass === '' && $reinstall && !empty($in['keep_pass'])) {
        $dbPass = (string)$existing['db']['pass'];
    }
    $adminPass = (string)($in['admin_pass'] ?? '');
    $mode = ($in['mode'] ?? 'keep') === 'reset' ? 'reset' : 'keep';

    $errors = [];
    if ($old['host'] === '' || !ctype_digit($old['port']) || $old['user'] === '') {
        $errors[] = 'กรุณากรอกข้อมูลเชื่อมต่อฐานข้อมูลให้ครบ';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $old['name'])) {
        $errors[] = 'ชื่อฐานข้อมูลใช้ได้เฉพาะ A-Z a-z 0-9 และ _';
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $old['username'])) {
        $errors[] = 'ชื่อผู้ใช้ admin ต้องยาว 3-60 ตัวอักษร (A-Z a-z 0-9 _ . -)';
    }
    if ($old['full_name'] === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ดูแลระบบ';
    }
    if (mb_strlen($adminPass) < 8) {
        $errors[] = 'รหัสผ่าน admin ต้องยาวอย่างน้อย 8 ตัวอักษร';
    } elseif ($adminPass !== (string)($in['admin_pass2'] ?? '')) {
        $errors[] = 'รหัสผ่านและการยืนยันไม่ตรงกัน';
    }
    if ($mode === 'reset' && trim((string)($in['confirm_reset'] ?? '')) !== 'RESET') {
        $errors[] = 'การล้างฐานข้อมูลต้องพิมพ์คำว่า RESET เพื่อยืนยัน';
    }

    $log = [];
    if (!$errors) {
        $cfg = ['host' => $old['host'], 'port' => (int)$old['port'], 'name' => $old['name'], 'user' => $old['user'], 'pass' => $dbPass];
        try {
            $server = Db::connect($cfg, false);
            $ver = mariadb_version($server);
            if ($ver === null || version_compare($ver, '10.0.0', '<')) {
                throw new RuntimeException('ต้องใช้ MariaDB 10 ขึ้นไป (ตรวจพบ: ' . (string)$server->query('SELECT VERSION()')->fetchColumn() . ')');
            }
            $log[] = "MariaDB $ver";
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $cfg['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = Db::connect($cfg);
            if ($mode === 'reset') {
                drop_all_tables($pdo);
                $log[] = 'ล้างตารางเดิมทั้งหมดแล้ว';
            }
            $ran = (new Migrator($pdo))->migrate();
            $log[] = $ran ? 'รัน migrations: ' . count($ran) . ' รายการ' : 'โครงสร้างฐานข้อมูลเป็นปัจจุบันแล้ว';

            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $st = $pdo->prepare('SELECT id FROM users WHERE username=?');
            $st->execute([$old['username']]);
            if ($id = $st->fetchColumn()) {
                $pdo->prepare("UPDATE users SET password_hash=?, full_name=?, role='admin', active=1 WHERE id=?")
                    ->execute([$hash, $old['full_name'], $id]);
                $log[] = 'อัปเดตบัญชีผู้ดูแลระบบเดิม';
            } else {
                $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,?,'admin')")
                    ->execute([$old['username'], $hash, $old['full_name']]);
                $log[] = 'สร้างบัญชีผู้ดูแลระบบใหม่';
            }
            write_config($cfg);
            file_put_contents(LOCK_FILE, date('c') . "\n");
            session_regenerate_id(true);
            unset($_SESSION['uid']);
            $done = true;
        } catch (Throwable $ex) {
            $errors[] = 'ติดตั้งไม่สำเร็จ: ' . $ex->getMessage();
        }
    }
    foreach ($errors as $er) {
        flash('error', $er);
    }
}

page_head('ติดตั้งระบบ');
?>
<div class="center-page"><div class="login-box wide">
  <div class="brand"><img src="<?= e(url('assets/vec-logo.png')) ?>" alt=""><small>สำนักพัฒนาสมรรถนะครูและบุคลากรอาชีวศึกษา (สสอ.)</small><b>ติดตั้งระบบบริหารที่พัก<?= $reinstall ? ' (ติดตั้งซ้ำ)' : '' ?></b></div>
  <?= render_flash() ?>

<?php if ($done): ?>
  <div class="alert alert-success">ติดตั้งเสร็จสมบูรณ์</div>
  <ul class="check"><?php foreach ($log as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
  <div class="alert alert-warn" style="margin-top:14px">เพื่อความปลอดภัย แนะนำให้จำกัดสิทธิ์การเข้าถึง <code>install.php</code> บนเซิร์ฟเวอร์จริง (การติดตั้งซ้ำต้องล็อกอินเป็น admin เสมอ)</div>
  <p><a class="btn" href="<?= e(url('login.php')) ?>">ไปหน้าเข้าสู่ระบบ</a></p>
<?php else: ?>
  <h2 style="margin-top:0">1. ตรวจสอบความพร้อมของระบบ</h2>
  <ul class="check">
  <?php foreach ($checks as $c): ?>
    <li><span><?= e($c['label']) ?> <span style="color:#8A8F98;font-size:12px">— <?= e($c['detail']) ?></span></span>
      <span class="badge <?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></span></li>
  <?php endforeach; ?>
  </ul>
  <?php if (!$canInstall): ?><div class="alert alert-error" style="margin-top:14px">กรุณาแก้ไขรายการที่ไม่ผ่านแล้วรีเฟรชหน้านี้</div><?php endif; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <h2>2. เชื่อมต่อฐานข้อมูล MariaDB 10+</h2>
    <div class="row">
      <div><label>โฮสต์</label><input type="text" name="host" value="<?= e($old['host']) ?>" required></div>
      <div><label>พอร์ต</label><input type="text" name="port" value="<?= e($old['port']) ?>" required></div>
      <div><label>ชื่อฐานข้อมูล (สร้างให้อัตโนมัติถ้ายังไม่มี)</label><input type="text" name="name" value="<?= e($old['name']) ?>" required></div>
      <div><label>ผู้ใช้ฐานข้อมูล</label><input type="text" name="user" value="<?= e($old['user']) ?>" required></div>
      <div><label>รหัสผ่านฐานข้อมูล (XAMPP เริ่มต้นเว้นว่างได้)</label><input type="password" name="pass" autocomplete="new-password"></div>
      <?php if ($reinstall): ?><div><label>&nbsp;</label><label style="display:flex;gap:8px;align-items:center;margin:10px 0"><input type="checkbox" name="keep_pass" value="1" checked> ใช้รหัสผ่านฐานข้อมูลเดิมหากเว้นว่าง</label></div><?php endif; ?>
    </div>

    <h2>3. ตั้งค่าผู้ดูแลระบบ (admin)</h2>
    <div class="row">
      <div><label>ชื่อผู้ใช้</label><input type="text" name="username" value="<?= e($old['username']) ?>" required></div>
      <div><label>ชื่อ–สกุล</label><input type="text" name="full_name" value="<?= e($old['full_name']) ?>" required></div>
      <div><label>รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label><input type="password" name="admin_pass" required autocomplete="new-password"></div>
      <div><label>ยืนยันรหัสผ่าน</label><input type="password" name="admin_pass2" required autocomplete="new-password"></div>
    </div>

    <?php if ($reinstall): ?>
    <h2>4. โหมดการติดตั้งซ้ำ</h2>
    <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:#1F1B1A"><input type="radio" name="mode" value="keep" checked> เก็บข้อมูลเดิม — รันเฉพาะ migrations ที่ค้างและอัปเดตบัญชี admin</label>
    <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:#A32638"><input type="radio" name="mode" value="reset"> ล้างข้อมูลทั้งหมดในฐานข้อมูลนี้แล้วติดตั้งใหม่</label>
    <label>พิมพ์ RESET เพื่อยืนยัน (เฉพาะโหมดล้างข้อมูล)</label><input type="text" name="confirm_reset">
    <?php endif; ?>

    <p style="margin-top:22px"><button class="btn" <?= $canInstall ? '' : 'disabled' ?>><?= $reinstall ? 'ติดตั้งซ้ำ' : 'เริ่มติดตั้ง' ?></button></p>
  </form>
<?php endif; ?>
</div></div>
<?php page_foot();
