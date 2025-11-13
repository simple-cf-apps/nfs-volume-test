<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set JSON content type as default
header('Content-Type: application/json');

// Get volume mount path from VCAP_SERVICES
function getVolumePath() {
    if (getenv('VCAP_SERVICES')) {
        $vcapServices = json_decode(getenv('VCAP_SERVICES'), true);
        $nfsService = $vcapServices['nfs'] ?? $vcapServices['nfs-volume'] ?? [];
        if (!empty($nfsService[0]['volume_mounts'][0]['container_dir'])) {
            return $nfsService[0]['volume_mounts'][0]['container_dir'];
        }
    }
    return '/var/vcap/data/nfs-test'; // fallback for local testing
}

$volumePath = getVolumePath();
$testFile = $volumePath . '/test-data.txt';
$instanceIndex = getenv('CF_INSTANCE_INDEX') ?: '0';
$instanceFile = $volumePath . '/instance-' . $instanceIndex . '.txt';

// Simple routing based on REQUEST_URI and REQUEST_METHOD
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Route: GET /
if ($requestUri === '/' && $requestMethod === 'GET') {
    $status = [
        'app_instance' => $instanceIndex,
        'hostname' => gethostname(),
        'volume_path' => $volumePath,
        'volume_exists' => is_dir($volumePath),
        'vcap_services' => getenv('VCAP_SERVICES') ? 'configured' : 'not configured',
        'php_version' => phpversion()
    ];
    
    echo json_encode($status, JSON_PRETTY_PRINT);
    exit;
}

// Route: POST /write
if ($requestUri === '/write' && $requestMethod === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $message = $input['message'] ?? 'test';
    $timestamp = date('c');
    $data = "[$timestamp] Instance $instanceIndex: $message\n";
    
    if (!is_dir($volumePath)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Volume not mounted',
            'path' => $volumePath
        ]);
        exit;
    }
    
    try {
        // Append to shared file
        file_put_contents($testFile, $data, FILE_APPEND | LOCK_EX);
        
        // Write instance-specific file
        file_put_contents($instanceFile, "Last write: $timestamp\n$message", LOCK_EX);
        
        echo json_encode([
            'success' => true,
            'wrote' => $data,
            'files' => [
                'shared' => $testFile,
                'instance' => $instanceFile
            ]
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'path' => $volumePath
        ]);
    }
    exit;
}

// Route: GET /read
if ($requestUri === '/read' && $requestMethod === 'GET') {
    try {
        $files = scandir($volumePath);
        $files = array_diff($files, ['.', '..']); // Remove . and ..
        
        $sharedData = file_exists($testFile) ? 
            file_get_contents($testFile) : 'No shared data yet';
        
        echo json_encode([
            'volume_path' => $volumePath,
            'files_in_volume' => array_values($files),
            'shared_data' => $sharedData,
            'instance' => $instanceIndex
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'path' => $volumePath
        ]);
    }
    exit;
}

// Route: GET /files
if ($requestUri === '/files' && $requestMethod === 'GET') {
    try {
        $files = [];
        $dir = scandir($volumePath);
        
        foreach ($dir as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $volumePath . '/' . $file;
            $stats = stat($filePath);
            
            $files[] = [
                'name' => $file,
                'size' => $stats['size'],
                'modified' => date('c', $stats['mtime'])
            ];
        }
        
        echo json_encode([
            'volume_path' => $volumePath,
            'file_count' => count($files),
            'files' => $files
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'path' => $volumePath
        ]);
    }
    exit;
}

// 404 for unknown routes
http_response_code(404);
echo json_encode(['error' => 'Not found']);
?>
