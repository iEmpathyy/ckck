<?php
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
$hwid = $_GET['hwid'] ?? '';

$pdo = new PDO('mysql:host=localhost;dbname=myhub', 'user', 'pass');

$stmt = $pdo->prepare("SELECT * FROM licenses WHERE license_key = ?");
$stmt->execute([$key]);
$license = $stmt->fetch();

if (!$license) {
    die(json_encode(['success' => false, 'message' => 'Invalid key']));
}

if ($license['expires'] < date('Y-m-d H:i:s')) {
    die(json_encode(['success' => false, 'message' => 'License expired']));
}

if (empty($license['hwid'])) {
    $stmt = $pdo->prepare("UPDATE licenses SET hwid = ? WHERE id = ?");
    $stmt->execute([$hwid, $license['id']]);
} elseif ($license['hwid'] !== $hwid) {
    die(json_encode(['success' => false, 'message' => 'HWID mismatch. Contact support.']));
}

echo json_encode(['success' => true, 'tier' => $license['tier']]);
?>
