<?php
/**
 * saveTaps.php - Click Logging Data Capture Backend
 * 
 * Purpose: Receives tap data from the click logging frontend (index.html) and stores it in Firebase Firestore
 * 
 * Expected POST Parameters:
 * - id: Session identifier (uniqueIdentifier from frontend)
 * - var: Device platform (android or pc)
 * - taps: JSON array of tap objects with sequence, start/end timestamps, and interface type
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/tap_logs_error.log');

// Set JSON response header
header('Content-Type: application/json');

try {
    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
        exit();
    }

    // Get POST data
    $sessionId = isset($_POST['id']) ? trim($_POST['id']) : null;
    $devicePlatform = isset($_POST['var']) ? trim($_POST['var']) : null;
    $tapsData = isset($_POST['taps']) ? $_POST['taps'] : null;

    // Validate required fields
    if (empty($sessionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing session identifier (id)']);
        exit();
    }

    if (empty($devicePlatform)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing device platform (var)']);
        exit();
    }

    if (empty($tapsData)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing tap data (taps)']);
        exit();
    }

    // Normalize device platform names
    $devicePlatform = strtolower($devicePlatform);
    if ($devicePlatform === 'android' || $devicePlatform === 'pc') {
        $devicePlatform = ucfirst($devicePlatform);
    }

    // Parse tap data - remove brackets and parse JSON array
    $tapsData = str_replace(['[', ']'], '', $tapsData);
    $tapArray = array_filter(explode(',', $tapsData), 'strlen');

    // Parse individual tap objects
    $taps = [];
    $currentTap = '';
    $braceCount = 0;

    foreach ($tapArray as $segment) {
        $segment = trim($segment);
        $braceCount += substr_count($segment, '{') - substr_count($segment, '}');
        $currentTap .= ($currentTap ? ',' : '') . $segment;

        if ($braceCount === 0 && !empty($currentTap)) {
            $tap = json_decode($currentTap, true);
            if ($tap === null) {
                error_log('Failed to decode tap: ' . $currentTap);
            } else {
                $taps[] = $tap;
            }
            $currentTap = '';
        }
    }

    // If parsing failed, try alternative approach
    if (empty($taps)) {
        // Alternative: extract tap objects more carefully
        $pattern = '/\{[^{}]*\}/';
        preg_match_all($pattern, $_POST['taps'], $matches);
        foreach ($matches[0] as $match) {
            $tap = json_decode($match, true);
            if ($tap !== null) {
                $taps[] = $tap;
            }
        }
    }

    if (empty($taps)) {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to parse tap data']);
        exit();
    }

    // Validate tap data structure
    foreach ($taps as $tap) {
        if (!isset($tap['tapSequenceNumber']) || !isset($tap['startTimestamp']) || !isset($tap['endTimestamp'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid tap data structure']);
            exit();
        }
    }

    // Get client IP and User-Agent for logging
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    // ===== FIREBASE FIRESTORE INTEGRATION =====
    // Check if Firebase Admin SDK is available
    $firebaseConfigPath = dirname(__FILE__) . '/../../../serviceAccountKey.json';
    
    if (file_exists($firebaseConfigPath)) {
        // Try to use Firebase if available
        try {
            require_once dirname(__FILE__) . '/../../../vendor/autoload.php';
            use Google\Cloud\Firestore\FirestoreClient;

            $firestore = new FirestoreClient();
            $collection = $firestore->collection('tap_logs');
            
            // Create session document
            $sessionDoc = [
                'sessionId' => $sessionId,
                'devicePlatform' => $devicePlatform,
                'createdAt' => new \Google\Cloud\Core\Timestamp(new \DateTime('now', new \DateTimeZone('UTC'))),
                'ipAddress' => $clientIP,
                'userAgent' => $userAgent,
                'tapCount' => count($taps),
                'taps' => []
            ];

            // Process each tap
            foreach ($taps as $tap) {
                $tapRecord = [
                    'sequence' => $tap['tapSequenceNumber'],
                    'startTimestamp' => floatval($tap['startTimestamp']),
                    'endTimestamp' => floatval($tap['endTimestamp']),
                    'duration' => floatval($tap['endTimestamp']) - floatval($tap['startTimestamp']),
                    'interfaceType' => $tap['interface'] ?? 'unknown',
                    'interfaceSequence' => $tap['interfaceSequence'] ?? 1,
                    'recordedAt' => new \Google\Cloud\Core\Timestamp(new \DateTime('now', new \DateTimeZone('UTC')))
                ];
                $sessionDoc['taps'][] = $tapRecord;
            }

            // Store in Firestore
            $collection->document($sessionId)->set($sessionDoc);
            
            // Log success
            error_log("Session $sessionId successfully stored in Firestore with " . count($taps) . " taps");
            
        } catch (Exception $e) {
            error_log("Firebase Firestore error: " . $e->getMessage());
            // Continue with local storage as fallback
        }
    }

    // ===== LOCAL FILE STORAGE (FALLBACK / BACKUP) =====
    // Store tap data locally as backup
    $logDir = dirname(__FILE__) . '/tap_data';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $sessionFile = $logDir . '/' . $sessionId . '.json';
    $sessionData = [
        'sessionId' => $sessionId,
        'devicePlatform' => $devicePlatform,
        'timestamp' => date('Y-m-d H:i:s'),
        'ipAddress' => $clientIP,
        'userAgent' => $userAgent,
        'tapCount' => count($taps),
        'taps' => $taps
    ];

    file_put_contents($sessionFile, json_encode($sessionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    error_log("Session $sessionId stored locally with " . count($taps) . " taps");

    // ===== SUCCESS RESPONSE =====
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Data saved successfully',
        'sessionId' => $sessionId,
        'tapCount' => count($taps),
        'devicePlatform' => $devicePlatform
    ]);

} catch (Exception $e) {
    error_log("Unexpected error in saveTaps.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'An error occurred while processing the tap data',
        'details' => $e->getMessage()
    ]);
}
?>
