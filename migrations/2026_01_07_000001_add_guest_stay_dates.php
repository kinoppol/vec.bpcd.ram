<?php
return [
    'title' => 'เพิ่มวันที่เข้า-ออกจริงต่อผู้เข้าพัก (guests.stay_start/stay_end) เพื่อคำนวณห้องว่างตามวันที่จริง',
    'up' => function (PDO $db) {
        $db->exec("ALTER TABLE `guests`
            ADD COLUMN `stay_start` DATE NULL AFTER `room_requested`,
            ADD COLUMN `stay_end` DATE NULL AFTER `stay_start`");
    },
    'down' => function (PDO $db) {
        $db->exec('ALTER TABLE `guests` DROP COLUMN `stay_start`, DROP COLUMN `stay_end`');
    },
];
