<?php
// 🔥 GITHUB-POWERED UEM LICENSE API - NO SERVER NEEDED!
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit('{}');

// 🎯 YOUR GITHUB REPO (CHANGE THIS)
define('GITHUB_REPO', 'YOURUSERNAME/uem-license-api');
define('GITHUB_TOKEN', ''); // Optional - create at github.com/settings/tokens

$input = array_merge($_GET, $_POST);
$json_input = @file_get_contents('php://input');
if ($json_input) {
    $json_data = @json_decode($json_input, true);
    if ($json_data) $input = array_merge($input, $json_data);
}

$action = strtoupper(trim($input['action'] ?? ''));

log_debug($input, $action);

switch ($action) {
    case 'ACTIVATE': echo json_encode(activate($input)); break;
    case 'VERIFY': echo json_encode(verify($input)); break;
    case 'DEACTIVATE': echo json_encode(deactivate($input)); break;
    case 'UPDATE-INFO': echo json_encode(update_info()); break;
    default: echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

const VALID_LICENSES = [
    'UEM-XYZ987-UVW654-RST321',
    'UEM-ABC123-DEF456-GHI789',
];

function github_api($path, $method = 'GET', $data = null) {
    global $github_token;
    
    $url = "https://api.github.com/repos/" . GITHUB_REPO . "/contents/$path";
    $headers = [
        'User-Agent: UEM-API',
        'Accept: application/vnd.github.v3+json'
    ];
    
    if ($github_token) $headers[] = "Authorization: token $github_token";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 15
    ]);
    
    if ($data) {
        $payload = [
            'message' => 'UEM License Update',
            'content' => base64_encode(json_encode($data, JSON_PRETTY_PRINT))
        ];
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        log_debug("GitHub API error $http_code: $response");
        return null;
    }
    
    return json_decode($response, true);
}

function load_data($filename, $default = []) {
    $file = github_api($filename);
    if (!$file || !isset($file['content'])) return $default;
    return json_decode(base64_decode($file['content']), true) ?: $default;
}

function save_data($filename, $data) {
    return github_api($filename, 'PUT', $data) !== null;
}

function activate($data) {
    $key = trim($data['license_key'] ?? '');
    $domain = trim($data['domain'] ?? '');
    
    if (empty($key) || empty($domain) || !in_array($key, VALID_LICENSES)) {
        return ['success' => false, 'message' => 'Invalid license or data'];
    }
    
    $licenses = load_data('uem_licenses.json', []);
    $licenses[] = [
        'key' => $key,
        'domain' => $domain,
        'active' => true,
        'time' => date('Y-m-d H:i:s'),
        'expires' => date('Y-m-d', strtotime('+365 days'))
    ];
    
    if (save_data('uem_licenses.json', $licenses)) {
        send_notification($domain, 'activated', $key);
        return ['success' => true, 'expires' => date('Y-m-d', strtotime('+365 days'))];
    }
    
    return ['success' => false, 'message' => 'Failed to save'];
}

function verify($data) {
    $key = trim($data['license_key'] ?? '');
    $domain = trim($data['domain'] ?? '');
    
    $licenses = load_data('uem_licenses.json', []);
    foreach ($licenses as $l) {
        if ($l['key'] === $key && $l['domain'] === $domain && ($l['active'] ?? false)) {
            return ['valid' => true];
        }
    }
    return ['valid' => false];
}

function deactivate($data) {
    $key = trim($data['license_key'] ?? '');
    $domain = trim($data['domain'] ?? '');
    
    $licenses = load_data('uem_licenses.json', []);
    $found = false;
    foreach ($licenses as $i => $l) {
        if ($l['key'] === $key && $l['domain'] === $domain) {
            $licenses[$i]['active'] = false;
            $found = true;
        }
    }
    
    if ($found && save_data('uem_licenses.json', $licenses)) {
        return ['success' => true];
    }
    return ['success' => false];
}

function update_info() {
    $headers = getallheaders();
    $headers = array_change_key_case($headers, CASE_LOWER);
    $auth = $headers['authorization'] ?? '';
    
    if (strpos($auth, 'Bearer ') === 0) {
        $key = trim(substr($auth, 7));
        if (in_array($key, VALID_LICENSES)) {
            return [
                'new_version' => '1.0.1',
                'download_url' => 'https://myinstitution.wuaze.com/uem-v1.0.1.zip'
            ];
        }
    }
    http_response_code(401);
    return ['error' => 'Unauthorized'];
}

function send_notification($domain, $action, $key) {
    $notifications = load_data('uem_notifications.json', []);
    $notifications[] = [
        'domain' => $domain,
        'action' => $action,
        'key' => $key,
        'time' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
    ];
    save_data('uem_notifications.json', $notifications);
}

function log_debug($input, $action) {
    $logs = load_data('uem_debug.log', []);
    $logs[] = [
        'time' => date('Y-m-d H:i:s'),
        'action' => $action,
        'input' => $input,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'N/A'
    ];
    // Keep only last 100 logs
    if (count($logs) > 100) $logs = array_slice($logs, -100);
    save_data('uem_debug.log', $logs);
}
?>
