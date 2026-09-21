<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(array $c, bool $withDb = true): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], (int)$c['port']);
        if ($withDb) {
            $dsn .= ';dbname=' . $c['name'];
        }
        return new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = app_config();
            if (!$cfg) {
                throw new RuntimeException('ยังไม่ได้ติดตั้งระบบ');
            }
            self::$pdo = self::connect($cfg['db']);
        }
        return self::$pdo;
    }
}
