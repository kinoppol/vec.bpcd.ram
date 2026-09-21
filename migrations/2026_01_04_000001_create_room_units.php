<?php
return [
    'title' => 'เพิ่มห้องย่อย/ห้องนอนภายในห้อง (room_units) และระบุห้องย่อยของผู้เข้าพัก',
    'up' => function (PDO $db) {
        $db->exec("CREATE TABLE `room_units` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `room_id` INT UNSIGNED NOT NULL,
            `label` VARCHAR(20) NOT NULL,
            `beds` TINYINT UNSIGNED NOT NULL DEFAULT 2,
            UNIQUE KEY `uq_unit` (`room_id`,`label`),
            CONSTRAINT `fk_unit_room` FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec('ALTER TABLE `guests` ADD COLUMN `unit_id` INT UNSIGNED NULL AFTER `room_id`,
            ADD CONSTRAINT `fk_g_unit` FOREIGN KEY (`unit_id`) REFERENCES `room_units`(`id`) ON DELETE SET NULL');
    },
    'down' => function (PDO $db) {
        $db->exec('ALTER TABLE `guests` DROP FOREIGN KEY `fk_g_unit`');
        $db->exec('ALTER TABLE `guests` DROP COLUMN `unit_id`');
        $db->exec('DROP TABLE IF EXISTS `room_units`');
    },
];
