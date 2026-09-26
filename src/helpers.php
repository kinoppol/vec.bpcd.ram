<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    $doc = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $root = str_replace('\\', '/', APP_ROOT);
    return $doc !== '' && str_starts_with($root, $doc) ? substr($root, strlen($doc)) : '';
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('CSRF token ไม่ถูกต้อง กรุณากลับไปรีเฟรชหน้าแล้วลองใหม่');
    }
}

function flash(?string $type = null, ?string $msg = null): ?array
{
    if ($type !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return null;
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function render_flash(): string
{
    $out = '';
    foreach (flash() ?? [] as [$t, $m]) {
        $out .= '<div class="alert alert-' . e($t) . '">' . e($m) . '</div>';
    }
    return $out;
}

/** path ชั่วคราวของไฟล์ที่อัปโหลด หรือ null ถ้าไม่ได้เลือกไฟล์ */
function uploaded(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์กำหนด)');
    }
    return $f['tmp_name'];
}
