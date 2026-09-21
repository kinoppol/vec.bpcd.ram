<?php
declare(strict_types=1);

/**
 * ไฟล์ใน migrations/ คืนค่า array: ['title' => string, 'up' => fn(PDO), 'down' => fn(PDO)]
 * ชื่อไฟล์ใช้เรียงลำดับ เช่น 2026_01_01_000001_create_users.php
 * หมายเหตุ: DDL ของ MariaDB commit อัตโนมัติ จึงไม่ครอบด้วย transaction
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir = APP_ROOT . '/migrations')
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(190) NOT NULL UNIQUE,
            `batch` INT UNSIGNED NOT NULL,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return array<string,string> name => path */
    private function files(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.php') ?: [] as $f) {
            $out[basename($f, '.php')] = $f;
        }
        ksort($out);
        return $out;
    }

    private function load(string $path): array
    {
        $m = require $path;
        if (!is_array($m) || !isset($m['up'], $m['down'])) {
            throw new RuntimeException('รูปแบบไฟล์ migration ไม่ถูกต้อง: ' . basename($path));
        }
        return $m;
    }

    private function nextBatch(): int
    {
        return (int)$this->pdo->query('SELECT COALESCE(MAX(batch),0) FROM migrations')->fetchColumn() + 1;
    }

    /** @return list<array{name:string,title:string,applied:bool,batch:?int,applied_at:?string}> */
    public function status(): array
    {
        $applied = [];
        foreach ($this->pdo->query('SELECT migration,batch,applied_at FROM migrations') as $r) {
            $applied[$r['migration']] = $r;
        }
        $rows = [];
        foreach ($this->files() as $name => $path) {
            $m = $this->load($path);
            $a = $applied[$name] ?? null;
            $rows[] = [
                'name' => $name,
                'title' => $m['title'] ?? $name,
                'applied' => $a !== null,
                'batch' => $a ? (int)$a['batch'] : null,
                'applied_at' => $a['applied_at'] ?? null,
            ];
        }
        return $rows;
    }

    /** @return list<string> ชื่อที่รันสำเร็จ */
    /** @param ?string $upTo รันเรียงตามลำดับจนถึง (และรวม) migration นี้ — ไม่ระบุ = รันทั้งหมดที่ค้าง */
    public function migrate(?string $upTo = null): array
    {
        $files = $this->files();
        if ($upTo !== null && !isset($files[$upTo])) {
            throw new RuntimeException("ไม่พบไฟล์ migration: $upTo");
        }
        $batch = $this->nextBatch();
        $ran = [];
        foreach ($this->status() as $row) {
            if ($row['applied']) {
                continue;
            }
            ($this->load($files[$row['name']])['up'])($this->pdo);
            $this->pdo->prepare('INSERT INTO migrations (migration,batch) VALUES (?,?)')
                ->execute([$row['name'], $batch]);
            $ran[] = $row['name'];
            if ($row['name'] === $upTo) {
                break;
            }
        }
        return $ran;
    }

    /** ย้อนกลับเฉพาะ migration ล่าสุด 1 รายการ @return list<string> */
    public function rollbackOne(): array
    {
        $name = $this->pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
        if ($name === false) {
            return [];
        }
        $files = $this->files();
        if (!isset($files[$name])) {
            throw new RuntimeException("ไม่พบไฟล์ migration: $name");
        }
        $m = $this->load($files[$name]);
        if (!empty($m['protected'])) {
            throw new RuntimeException("ย้อนกลับไม่ได้: $name เป็น migration หลักของระบบ");
        }
        ($m['down'])($this->pdo);
        $this->pdo->prepare('DELETE FROM migrations WHERE migration=?')->execute([$name]);
        return [$name];
    }

    /** ชื่อ migration ที่ป้องกันไม่ให้ย้อนกลับ */
    public function isProtected(string $name): bool
    {
        $files = $this->files();
        return isset($files[$name]) && !empty($this->load($files[$name])['protected']);
    }

    /** ย้อนกลับ batch ล่าสุด */
    public function rollback(): array
    {
        $batch = $this->nextBatch() - 1;
        if ($batch <= 0) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT migration FROM migrations WHERE batch=? ORDER BY id DESC');
        $st->execute([$batch]);
        $files = $this->files();
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($names as $name) {
            if (!isset($files[$name])) {
                throw new RuntimeException("ไม่พบไฟล์ migration: $name");
            }
            if (!empty($this->load($files[$name])['protected'])) {
                throw new RuntimeException("ย้อนกลับไม่ได้: $name เป็น migration หลักของระบบ (ใช้ติดตั้งซ้ำแบบล้างข้อมูลที่ install.php แทน)");
            }
        }
        $done = [];
        foreach ($names as $name) {
            ($this->load($files[$name])['down'])($this->pdo);
            $this->pdo->prepare('DELETE FROM migrations WHERE migration=?')->execute([$name]);
            $done[] = $name;
        }
        return $done;
    }
}
