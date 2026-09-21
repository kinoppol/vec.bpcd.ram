<?php
return [
    'title' => 'สร้างตารางผู้ใช้งาน (users)',
    'protected' => true, // ห้ามย้อนกลับจากเมนู Migrations (จะทำให้ admin เข้าระบบไม่ได้)
    'up' => function (PDO $db) {
        $db->exec("CREATE TABLE `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(60) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(150) NOT NULL,
            `dept` VARCHAR(150) NULL,
            `role` ENUM('admin','exec','caretaker','owner') NOT NULL DEFAULT 'owner',
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    },
    'down' => fn(PDO $db) => $db->exec('DROP TABLE IF EXISTS `users`'),
];
