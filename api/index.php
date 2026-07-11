<?php
/**
 * CallApp Phone Number Lookup API
 * Complete solution with web interface and API endpoint
 * 
 * Features:
 * - Web interface for phone number lookup
 * - REST API endpoint
 * - SSL error handling
 * - Rate limiting
 * - Caching
 * - Error logging
 */

// Configuration
define('ENVIRONMENT', 'production'); // development or production
define('ENABLE_CACHE', true);
define('CACHE_DURATION', 3600); // 1 hour
define('ENABLE_RATE_LIMITING', true);
define('MAX_REQUESTS_PER_MINUTE', 30);
define('LOG_ERRORS', true);
define('LOG_FILE', __DIR__ . '/logs/callapp-errors.log');

// Start session for rate limiting
if (ENABLE_RATE_LIMITING) {
    session_start();
}

// Set error reporting
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set JSON response header for API calls
if (isset($_GET['api']) || isset($_GET['number'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

/**
 * Log errors to file
 */
function logError($message, $data = []) {
    if (!LOG_ERRORS) return;
    
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message} " . json_encode($data) . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
}

/**
 * Rate limiting check
 */
function checkRateLimit() {
    if (!ENABLE_RATE_LIMITING) return true;
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $timeWindow = 60; // 1 minute
    $maxRequests = MAX_REQUESTS_PER_MINUTE;
    
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    
    $now = time();
    $requests = $_SESSION['rate_limit'];
    
    // Remove old requests outside the time window
    $requests = array_filter($requests, function($timestamp) use ($now, $timeWindow) {
        return ($now - $timestamp) < $timeWindow;
    });
    
    // Check if limit exceeded
    if (count($requests) >= $maxRequests) {
        return false;
    }
    
    // Add current request
    $requests[] = $now;
    $_SESSION['rate_limit'] = $requests;
    
    return true;
}

/**
 * Get cached response
 */
function getCached($key) {
    if (!ENABLE_CACHE) return false;
    
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';
    if (!file_exists($cacheFile)) return false;
    
    $data = unserialize(file_get_contents($cacheFile));
    if (!$data || !isset($data['expires']) || time() > $data['expires']) {
        @unlink($cacheFile);
        return false;
    }
    
    return $data['response'];
}

/**
 * Save to cache
 */
function setCache($key, $response) {
    if (!ENABLE_CACHE) return;
    
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';
    $data = [
        'expires' => time() + CACHE_DURATION,
        'response' => $response
    ];
    file_put_contents($cacheFile, serialize($data));
}

/**
 * Fetch CallApp information
 */
function fetchCallAppInfo($number, $forceRefresh = false) {
    // Cache key
    $cacheKey = 'callapp_' . $number;
    
    // Check cache
    if (!$forceRefresh) {
        $cached = getCached($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    // Clean the number
    $cleanedNumber = preg_replace('/[^0-9+]/', '', $number);
    if (strpos($cleanedNumber, '+') !== 0) {
        $cleanedNumber = '+' . $cleanedNumber;
    }
    
    // Prepare parameters
    $params = [
        'cpn' => $cleanedNumber,
        'myp' => 'fb.877409278562861',
        'ibs' => '0',
        'cid' => '0',
        'tk' => '0080528975',
        'cvc' => '2268'
    ];
    
    $queryString = http_build_query($params);
    $fullUrl = 'https://s.callapp.com/callapp-server/csrch?' . $queryString;
    
    // Headers
    $headers = [
        'Host: s.callapp.com',
        'User-Agent: Mozilla/5.0 (Linux; Android 12; ONEPLUS A6013 Build/SQ3A.220705.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/108.0.5359.128 Mobile Safari/537.36',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-site'
    ];
    
    // Initialize cURL
    $ch = curl_init();
    
    // cURL options
    $curlOptions = [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING => '',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_VERBOSE => false
    ];
    
    curl_setopt_array($ch, $curlOptions);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    
    curl_close($ch);
    
    // Handle errors
    if ($curlError) {
        $errorMessage = 'cURL Error: ' . $curlError;
        logError($errorMessage, ['number' => $cleanedNumber, 'errno' => $curlErrno]);
        
        $result = [
            'success' => false,
            'status' => 500,
            'error' => 'Failed to connect to CallApp service',
            'number' => $cleanedNumber,
            'debug' => (ENVIRONMENT === 'development') ? [
                'curl_error' => $curlError,
                'curl_errno' => $curlErrno
            ] : null
        ];
        
        setCache($cacheKey, $result);
        return $result;
    }
    
    // Process response
    if ($httpCode === 200) {
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            $result = [
                'success' => true,
                'status' => 200,
                'data' => $decodedResponse,
                'number' => $cleanedNumber
            ];
        } else {
            // Check if it's HTML (SSL error or redirect)
            if (strpos($response, '<html') !== false || strpos($response, '<!DOCTYPE') !== false) {
                $result = [
                    'success' => false,
                    'status' => 525,
                    'error' => 'SSL/TLS handshake failed or server redirected',
                    'number' => $cleanedNumber,
                    'debug' => (ENVIRONMENT === 'development') ? [
                        'response_preview' => substr($response, 0, 500)
                    ] : null
                ];
            } else {
                $result = [
                    'success' => true,
                    'status' => 200,
                    'data' => $response,
                    'number' => $cleanedNumber
                ];
            }
        }
        
        setCache($cacheKey, $result);
        return $result;
    }
    
    // Other HTTP errors
    $errorMessages = [
        401 => 'Unauthorized - Token expired or invalid',
        403 => 'Forbidden - Access denied',
        404 => 'Not Found - Endpoint may have changed',
        429 => 'Too Many Requests - Rate limit exceeded',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        525 => 'SSL Handshake Failed'
    ];
    
    $errorMessage = isset($errorMessages[$httpCode]) 
        ? $errorMessages[$httpCode] 
        : 'Request failed with status code: ' . $httpCode;
    
    logError($errorMessage, ['number' => $cleanedNumber, 'http_code' => $httpCode]);
    
    $result = [
        'success' => false,
        'status' => $httpCode,
        'error' => $errorMessage,
        'number' => $cleanedNumber,
        'debug' => (ENVIRONMENT === 'development') ? [
            'response_preview' => substr($response, 0, 500)
        ] : null
    ];
    
    setCache($cacheKey, $result);
    return $result;
}

/**
 * Validate phone number
 */
function validatePhoneNumber($number) {
    $cleaned = preg_replace('/[^0-9+]/', '', $number);
    $digitsOnly = preg_replace('/[^0-9]/', '', $cleaned);
    
    if (strlen($digitsOnly) < 10) {
        return false;
    }
    
    return $cleaned;
}

/**
 * Display web interface
 */
function displayWebInterface($result = null, $number = '') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CallApp Phone Lookup</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            
            .container {
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                padding: 40px;
                max-width: 800px;
                width: 100%;
            }
            
            h1 {
                color: #333;
                font-size: 28px;
                margin-bottom: 10px;
                text-align: center;
            }
            
            .subtitle {
                color: #666;
                text-align: center;
                margin-bottom: 30px;
                font-size: 14px;
            }
            
            .search-box {
                display: flex;
                gap: 10px;
                margin-bottom: 30px;
                flex-wrap: wrap;
            }
            
            .search-box input {
                flex: 1;
                padding: 15px 20px;
                border: 2px solid #e0e0e0;
                border-radius: 12px;
                font-size: 16px;
                transition: border-color 0.3s;
                min-width: 200px;
            }
            
            .search-box input:focus {
                outline: none;
                border-color: #667eea;
            }
            
            .search-box button {
                padding: 15px 30px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 12px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
                white-space: nowrap;
            }
            
            .search-box button:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
            }
            
            .search-box button:active {
                transform: translateY(0);
            }
            
            .search-box button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            
            .examples {
                text-align: center;
                margin-bottom: 30px;
                font-size: 13px;
                color: #666;
            }
            
            .examples span {
                display: inline-block;
                background: #f0f0f0;
                padding: 5px 12px;
                border-radius: 6px;
                margin: 3px;
                cursor: pointer;
                transition: background 0.2s;
            }
            
            .examples span:hover {
                background: #e0e0e0;
            }
            
            .result-container {
                margin-top: 20px;
                border-top: 2px solid #f0f0f0;
                padding-top: 20px;
            }
            
            .result {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 20px;
                overflow: auto;
                max-height: 500px;
            }
            
            .result pre {
                font-family: 'Courier New', monospace;
                font-size: 13px;
                white-space: pre-wrap;
                word-wrap: break-word;
                margin: 0;
            }
            
            .result.error {
                background: #fef2f2;
                border-left: 4px solid #ef4444;
                color: #991b1b;
            }
            
            .result.success {
                background: #f0fdf4;
                border-left: 4px solid #22c55e;
            }
            
            .status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 600;
                margin-bottom: 10px;
            }
            
            .status-badge.success {
                background: #22c55e;
                color: white;
            }
            
            .status-badge.error {
                background: #ef4444;
                color: white;
            }
            
            .status-badge.info {
                background: #3b82f6;
                color: white;
            }
            
            .loading {
                text-align: center;
                padding: 40px;
                color: #666;
            }
            
            .loading::after {
                content: '...';
                animation: dots 1.5s steps(4, end) infinite;
            }
            
            @keyframes dots {
                0% { content: ''; }
                25% { content: '.'; }
                50% { content: '..'; }
                75% { content: '...'; }
            }
            
            .clear-cache {
                text-align: center;
                margin-top: 15px;
            }
            
            .clear-cache button {
                background: none;
                border: none;
                color: #666;
                font-size: 12px;
                cursor: pointer;
                text-decoration: underline;
            }
            
            .clear-cache button:hover {
                color: #333;
            }
            
            @media (max-width: 600px) {
                .container {
                    padding: 20px;
                }
                
                .search-box input {
                    min-width: 100%;
                }
                
                .search-box button {
                    width: 100%;
                }
                
                h1 {
                    font-size: 22px;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>📞 CallApp Lookup</h1>
            <p class="subtitle">Find caller information using phone number</p>
            
            <form method="GET" action="" id="searchForm">
                <div class="search-box">
                    <input type="text" 
                           name="number" 
                           id="phoneInput" 
                           placeholder="Enter phone number (e.g., 919872678971 or +919872678971)"
                           value="<?php echo htmlspecialchars($number); ?>"
                           required>
                    <button type="submit" id="searchBtn">🔍 Search</button>
                </div>
            </form>
            
            <div class="examples">
                <strong>Try:</strong>
                <span onclick="setNumber('919872678971')">+91 9872678971</span>
                <span onclick="setNumber('919876543210')">+91 9876543210</span>
                <span onclick="setNumber('919999999999')">+91 9999999999</span>
            </div>
            
            <?php if ($result): ?>
                <div class="result-container">
                    <div class="result <?php echo $result['success'] ? 'success' : 'error'; ?>">
                        <?php if ($result['success']): ?>
                            <div class="status-badge success">✅ Success</div>
                            <pre><?php 
                                if (isset($result['data'])) {
                                    echo htmlspecialchars(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                } else {
                                    echo htmlspecialchars(print_r($result, true));
                                }
                            ?></pre>
                        <?php else: ?>
                            <div class="status-badge error">❌ Error</div>
                            <p><strong>Status:</strong> <?php echo $result['status'] ?? 'N/A'; ?></p>
                            <p><strong>Message:</strong> <?php echo htmlspecialchars($result['error'] ?? 'Unknown error'); ?></p>
                            <?php if (isset($result['debug']) && ENVIRONMENT === 'development'): ?>
                                <pre><?php echo htmlspecialchars(print_r($result['debug'], true)); ?></pre>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="clear-cache">
                <form method="GET" action="">
                    <input type="hidden" name="clear_cache" value="1">
                    <button type="submit">🗑️ Clear Cache</button>
                </form>
            </div>
        </div>
        
        <script>
            function setNumber(number) {
                document.getElementById('phoneInput').value = number;
                document.getElementById('searchForm').submit();
            }
            
            // Auto-submit if number is entered
            document.getElementById('phoneInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('searchForm').submit();
                }
            });
            
            // Show loading state
            document.getElementById('searchForm').addEventListener('submit', function() {
                const btn = document.getElementById('searchBtn');
                btn.disabled = true;
                btn.textContent = 'Searching...';
            });
        </script>
    </body>
    </html>
    <?php
}

// --- MAIN APPLICATION ---

// Clear cache if requested
if (isset($_GET['clear_cache'])) {
    $cacheDir = __DIR__ . '/cache';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*.cache');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit();
}

// Check rate limit for API requests
if (isset($_GET['number']) && !checkRateLimit()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'status' => 429,
        'error' => 'Rate limit exceeded. Please wait a moment and try again.',
        'message' => 'Maximum ' . MAX_REQUESTS_PER_MINUTE . ' requests per minute'
    ]);
    exit();
}

// Handle API request
if (isset($_GET['number'])) {
    $number = trim($_GET['number']);
    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
    
    // Validate phone number
    $validatedNumber = validatePhoneNumber($number);
    
    if ($validatedNumber === false) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'status' => 400,
            'error' => 'Invalid phone number format',
            'message' => 'Please provide a valid phone number with at least 10 digits',
            'provided' => $number
        ]);
        exit();
    }
    
    // Fetch information
    $result = fetchCallAppInfo($validatedNumber, $forceRefresh);
    
    // Set response code
    http_response_code($result['status'] ?? 500);
    
    // Output JSON
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

// Display web interface
$result = null;
$number = '';

// Check if we have a result to display
if (isset($_GET['number'])) {
    $number = trim($_GET['number']);
    $validatedNumber = validatePhoneNumber($number);
    
    if ($validatedNumber !== false) {
        $result = fetchCallAppInfo($validatedNumber);
    }
}

displayWebInterface($result, $number);
?>