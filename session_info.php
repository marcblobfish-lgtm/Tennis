<?php
require_once __DIR__ . '/session_start_sicher.php';
header('Content-Type: application/json; charset=utf-8');

if (isset($_SESSION['benutzer'])) {
    echo json_encode([
        'eingeloggt' => true,
        'fullname' => $_SESSION['benutzer']['fullname'],
        'rolle' => $_SESSION['benutzer']['rolle']
    ]);
} else {
    echo json_encode(['eingeloggt' => false]);
}
