<?php
declare(strict_types=1);

final class Auth
{
    public const ROLES = [
        'admin' => 'ผู้ดูแลระบบ',
        'exec' => 'ผู้บริหาร สสอ.',
        'caretaker' => 'ผู้ดูแลห้องพัก/ห้องประชุม',
        'owner' => 'เจ้าของโครงการ',
    ];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'path' => base_url() . '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']),
            ]);
            session_name('vecram');
            session_start();
        }
    }

    public static function attempt(string $username, string $password): bool
    {
        $st = Db::pdo()->prepare('SELECT * FROM users WHERE username=? AND active=1');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        return true;
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['uid']) || !is_installed()) {
            return null;
        }
        try {
            $st = Db::pdo()->prepare('SELECT id,username,full_name,role,dept FROM users WHERE id=? AND active=1');
            $st->execute([$_SESSION['uid']]);
            return $st->fetch() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param string|list<string>|null $roles บทบาทที่อนุญาต (admin เข้าได้ทุกหน้า) */
    public static function require(string|array|null $roles = null): array
    {
        $role = $roles === null ? null : (array)$roles;
        if (!is_installed()) {
            redirect('install.php');
        }
        $u = self::user();
        if (!$u) {
            redirect('login.php');
        }
        if ($role !== null && $u['role'] !== 'admin' && !in_array($u['role'], $role, true)) {
            http_response_code(403);
            exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
        }
        return $u;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
