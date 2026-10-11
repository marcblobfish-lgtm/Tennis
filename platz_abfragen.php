<?php
require_once __DIR__ . '/platzdaten_funktionen.php';
header('Content-Type: application/json; charset=utf-8');

// Punkt 7: Anmeldung prüfen
tn_login_pflicht();

$datum = is_string($_GET['datum'] ?? null) ? $_GET['datum'] : '';
if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $datum)) {
    echo json_encode([]);
    exit;
}

[$data, $leseOk] = tn_json_lesen(__DIR__ . '/platzdaten/' . $datum . '.json');
echo json_encode($leseOk ? $data : [], JSON_UNESCAPED_UNICODE);
