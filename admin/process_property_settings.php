<?php
ini_set('display_errors', 0);
require_once __DIR__ . '/../config.php';
bb_require_admin();
header('Content-Type: application/json');

$DATA_FILE = __DIR__ . '/data/property_settings.json';

function load_prop_settings($f) {
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}
function save_prop_settings($f, $d) {
    $dir = dirname($f);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'settings' => load_prop_settings($DATA_FILE)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
}

$key = trim($_POST['key'] ?? '');
if (!$key) { echo json_encode(['success'=>false,'message'=>'Property key required']); exit; }

$settings = load_prop_settings($DATA_FILE);
$entry    = $settings[$key] ?? [];

if (isset($_POST['name']))        $entry['name']        = trim($_POST['name']);
if (isset($_POST['description'])) $entry['description'] = trim($_POST['description']);

// File upload
if (!empty($_FILES['image']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','svg'])) {
        echo json_encode(['success'=>false,'message'=>'Invalid image type.']); exit;
    }
    $destDir = __DIR__ . '/../assets/images/properties';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $dest = $destDir . '/' . $key . '_custom.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        echo json_encode(['success'=>false,'message'=>'Failed to save image.']); exit;
    }
    $entry['image'] = '../assets/images/properties/' . $key . '_custom.' . $ext;
} elseif (!empty($_POST['image_url'])) {
    $entry['image'] = trim($_POST['image_url']);
}

$settings[$key] = $entry;
save_prop_settings($DATA_FILE, $settings);
echo json_encode(['success'=>true,'entry'=>$entry]);