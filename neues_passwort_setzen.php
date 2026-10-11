<?php
// ENTFERNT (Sicherheitsbericht Punkt 3). Diese Datei kann gelöscht werden.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'msg' => 'Diese Funktion wurde entfernt.']);
