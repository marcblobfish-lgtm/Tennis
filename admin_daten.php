<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');
tn_admin_pflicht();

$dir = __DIR__ . '/platzdaten';
$allBookings = [];

if (is_dir($dir)) {
    foreach (glob($dir . '/*.json') as $file) {
        // nur Tagesdateien (YYYY-MM-DD.json); accounts.json u. a. nie ausliefern
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\.json$/', basename($file))) continue;
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data)) {
            foreach ($data as $booking) {
                $allBookings[] = $booking;
            }
        }
    }
}

echo json_encode($allBookings, JSON_UNESCAPED_UNICODE);
