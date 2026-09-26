<?php
return [
    'title' => 'เพิ่มประเภทห้องพร้อมรูปห้องตัวอย่าง (room_types) และระบุประเภทของแต่ละห้อง (rooms.room_type_id)',
    'up' => function (PDO $db) {
        $db->exec("CREATE TABLE `room_types` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `note` VARCHAR(255) NULL,
            `image` VARCHAR(120) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec('ALTER TABLE `rooms` ADD COLUMN `room_type_id` INT UNSIGNED NULL AFTER `type`,
            ADD CONSTRAINT `fk_rooms_rtype` FOREIGN KEY (`room_type_id`) REFERENCES `room_types`(`id`) ON DELETE SET NULL');
    },
    'down' => function (PDO $db) {
        $db->exec('ALTER TABLE `rooms` DROP FOREIGN KEY `fk_rooms_rtype`');
        $db->exec('ALTER TABLE `rooms` DROP COLUMN `room_type_id`');
        $db->exec('DROP TABLE IF EXISTS `room_types`');
    },
];
