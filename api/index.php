<?php
/**
 * CallApp Phone Lookup - Enhanced SSL Fix
 * Multiple methods to bypass SSL issues
 */

// Configuration
define('ENVIRONMENT', 'production');
define('ENABLE_CACHE', true);
define('CACHE_DURATION', 300); // 5 minutes
define('LOG_ERRORS', true);
define('LOG_FILE', __DIR__ . '/logs/callapp-errors.log');

// Start session for rate limiting
session_start();

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

/**
 * Enhanced fetch with multiple fallback methods
 */
function fetchCallAppInfoEnhanced($number) {
    $cacheKey = 'callapp_' . $number;
    
    // Check cache
    if (ENABLE_CACHE) {
        $cached = getCached($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    // Clean number
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
    
    // Try multiple methods
    $methods = [
        'curl_ssl_bypass' => function() use ($fullUrl) {
            return fetchWithCurlSSLBypass($fullUrl);
        },
        'curl_cloudflare_bypass' => function() use ($fullUrl) {
            return fetchWithCurlCloudflareBypass($fullUrl);
        },
        'curl_firefox_ua' => function() use ($fullUrl) {
            return fetchWithCurlFirefoxUA($fullUrl);
        },
        'file_get_contents' => function() use ($fullUrl) {
            return fetchWithFileGetContents($fullUrl);
        },
        'socket' => function() use ($fullUrl) {
            return fetchWithSocket($fullUrl);
        }
    ];
    
    $lastError = null;
    
    foreach ($methods as $methodName => $method) {
        try {
            $result = $method();
            if ($result && isset($result['success']) && $result['success'] === true) {
                // Cache successful result
                if (ENABLE_CACHE) {
                    setCache($cacheKey, $result);
                }
                return $result;
            }
            if ($result && isset($result['error'])) {
                $lastError = $result['error'];
            }
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            continue;
        }
    }
    
    // All methods failed
    $errorResult = [
        'success' => false,
        'status' => 525,
        'error' => 'All connection methods failed. Last error: ' . $lastError,
        'number' => $cleanedNumber,
        'debug' => [
            'methods_tried' => array_keys($methods),
            'last_error' => $lastError
        ]
    ];
    
    if (ENABLE_CACHE) {
        setCache($cacheKey, $errorResult);
    }
    return $errorResult;
}

/**
 * Method 1: cURL with SSL bypass
 */
function fetchWithCurlSSLBypass($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Cache-Control: no-cache'
        ],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => ''
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'cURL SSL Bypass failed: ' . $error];
    }
    
    if ($httpCode === 200) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['success' => true, 'data' => $decoded];
        }
        return ['success' => true, 'data' => $response];
    }
    
    return ['success' => false, 'error' => 'HTTP ' . $httpCode];
}

/**
 * Method 2: cURL with CloudFlare bypass headers
 */
function fetchWithCurlCloudflareBypass($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Cache-Control: max-age=0'
        ],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'CloudFlare bypass failed: ' . $error];
    }
    
    if ($httpCode === 200) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['success' => true, 'data' => $decoded];
        }
        return ['success' => true, 'data' => $response];
    }
    
    return ['success' => false, 'error' => 'HTTP ' . $httpCode];
}

/**
 * Method 3: cURL with Firefox User-Agent
 */
function fetchWithCurlFirefoxUA($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: cross-site'
        ],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_ENCODING => ''
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => 'Firefox UA failed: ' . $error];
    }
    
    if ($httpCode === 200) {
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['success' => true, 'data' => $decoded];
        }
        return ['success' => true, 'data' => $response];
    }
    
    return ['success' => false, 'error' => 'HTTP ' . $httpCode];
}

/**
 * Method 4: file_get_contents with stream context
 */
function fetchWithFileGetContents($url) {
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'ciphers' => 'DHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256'
        ],
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: application/json, text/plain, */*',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive'
            ],
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'file_get_contents failed'];
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['success' => true, 'data' => $decoded];
    }
    
    return ['success' => true, 'data' => $response];
}

/**
 * Method 5: Socket connection (direct)
 */
function fetchWithSocket($url) {
    $parsed = parse_url($url);
    $host = $parsed['host'];
    $path = $parsed['path'] . '?' . $parsed['query'];
    $port = 443;
    
    $timeout = 30;
    $errno = 0;
    $errstr = '';
    
    $fp = @fsockopen('ssl://' . $host, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        return ['success' => false, 'error' => 'Socket connection failed: ' . $errstr];
    }
    
    $request = "GET $path HTTP/1.1\r\n";
    $request .= "Host: $host\r\n";
    $request .= "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n";
    $request .= "Accept: application/json\r\n";
    $request .= "Connection: close\r\n";
    $request .= "\r\n";
    
    fwrite($fp, $request);
    
    $response = '';
    while (!feof($fp)) {
        $response .= fgets($fp, 4096);
    }
    fclose($fp);
    
    // Extract body
    $parts = explode("\r\n\r\n", $response, 2);
    if (count($parts) < 2) {
        return ['success' => false, 'error' => 'Invalid response'];
    }
    
    $body = $parts[1];
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['success' => true, 'data' => $decoded];
    }
    
    return ['success' => true, 'data' => $body];
}

/**
 * Cache functions
 */
function getCached($key) {
    if (!ENABLE_CACHE) return false;
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';
    if (!file_exists($cacheFile)) return false;
    
    $data = unserialize(file_get_contents($cacheFile));
    if (!$data || !isset($data['expires']) || time() > $data['expires']) {
        @unlink($cacheFile);
        return false;
    }
    return $data['response'];
}

function setCache($key, $response) {
    if (!ENABLE_CACHE) return;
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.cache';
    $data = [
        'expires' => time() + CACHE_DURATION,
        'response' => $response
    ];
    file_put_contents($cacheFile, serialize($data));
}

/**
 * Validate phone number
 */
function validatePhoneNumber($number) {
    $cleaned = preg_replace('/[^0-9+]/', '', $number);
    $digitsOnly = preg_replace('/[^0-9]/', '', $cleaned);
    if (strlen($digitsOnly) < 10) return false;
    return $cleaned;
}

// --- API Response ---
if (isset($_GET['ajax']) || isset($_GET['number'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    $number = trim($_GET['number'] ?? '');
    if (empty($number)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Phone number required']);
        exit();
    }
    
    $validated = validatePhoneNumber($number);
    if ($validated === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid phone number format']);
        exit();
    }
    
    $result = fetchCallAppInfoEnhanced($validated);
    http_response_code($result['status'] ?? 200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📞 CallApp Lookup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 900px;
            width: 100%;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 32px;
            color: #333;
        }
        .header .emoji { font-size: 40px; }
        .header p { color: #666; font-size: 14px; }
        .search-section {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .input-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .input-group input {
            flex: 1;
            padding: 14px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
            min-width: 200px;
        }
        .input-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102,126,234,0.1);
        }
        .input-group button {
            padding: 14px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }
        .input-group button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102,126,234,0.4);
        }
        .input-group button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .examples {
            margin-top: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .examples .example-btn {
            padding: 4px 14px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            color: #333;
        }
        .examples .example-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .result-container { margin-top: 20px; display: none; }
        .result-container.show { display: block; }
        .result-box {
            border-radius: 16px;
            padding: 20px;
            overflow: auto;
            max-height: 500px;
        }
        .result-box.success {
            background: #f0fdf4;
            border: 2px solid #22c55e;
        }
        .result-box.error {
            background: #fef2f2;
            border: 2px solid #ef4444;
        }
        .result-box.loading {
            background: #f3f4f6;
            border: 2px solid #9ca3af;
            text-align: center;
            padding: 40px;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .status-badge.success { background: #22c55e; color: white; }
        .status-badge.error { background: #ef4444; color: white; }
        .result-box pre {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .loader {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid #e0e0e0;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
            flex-wrap: wrap;
        }
        .stats .stat-item { font-size: 13px; color: #666; }
        .stats .stat-item strong { color: #333; }
        .clear-cache-btn {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 12px;
            cursor: pointer;
            text-decoration: underline;
        }
        .clear-cache-btn:hover { color: #333; }
        @media (max-width: 600px) {
            .container { padding: 20px; }
            .input-group input { min-width: 100%; }
            .input-group button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="emoji">📞</div>
            <h1>CallApp Lookup</h1>
            <p>Find caller information by phone number</p>
        </div>
        
        <div class="search-section">
            <div class="input-group">
                <input type="text" id="phoneInput" placeholder="Enter phone number with country code" autofocus>
                <button id="searchBtn">🔍 Search</button>
            </div>
            <div class="examples">
                <span>Try:</span>
                <button class="example-btn" data-number="919872678971">+91 9872678971</button>
                <button class="example-btn" data-number="919876543210">+91 9876543210</button>
                <button class="example-btn" data-number="7063129573">+91 7063129573</button>
            </div>
        </div>
        
        <div id="resultContainer" class="result-container">
            <div id="resultBox" class="result-box"></div>
            <div class="stats">
                <div class="stat-item"><strong>Status:</strong> <span id="statusText">Ready</span></div>
                <div class="stat-item"><strong>Number:</strong> <span id="numberText">-</span></div>
                <button class="clear-cache-btn" id="clearCacheBtn">🗑️ Clear Cache</button>
            </div>
        </div>
    </div>
    
    <script>
        const phoneInput = document.getElementById('phoneInput');
        const searchBtn = document.getElementById('searchBtn');
        const resultContainer = document.getElementById('resultContainer');
        const resultBox = document.getElementById('resultBox');
        const statusText = document.getElementById('statusText');
        const numberText = document.getElementById('numberText');
        const clearCacheBtn = document.getElementById('clearCacheBtn');
        
        document.querySelectorAll('.example-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                phoneInput.value = this.dataset.number;
                performSearch();
            });
        });
        
        phoneInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); performSearch(); }
        });
        
        searchBtn.addEventListener('click', performSearch);
        
        clearCacheBtn.addEventListener('click', function() {
            if (confirm('Clear cache?')) {
                fetch('?clear_cache=1').then(() => { location.reload(); });
            }
        });
        
        function performSearch() {
            const number = phoneInput.value.trim();
            if (!number) { showError('Please enter a phone number'); return; }
            
            showLoading();
            
            fetch(`?number=${encodeURIComponent(number)}&ajax=1`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess(data);
                    } else {
                        showError(data.error || 'Unknown error', data);
                    }
                })
                .catch(error => {
                    showError('Network error: ' + error.message);
                });
        }
        
        function showLoading() {
            resultContainer.classList.add('show');
            resultBox.className = 'result-box loading';
            resultBox.innerHTML = `
                <div class="loader"></div>
                <p style="margin-top: 16px; color: #666;">Searching for caller information...</p>
                <p style="margin-top: 8px; font-size: 12px; color: #999;">Trying multiple connection methods...</p>
            `;
            statusText.textContent = 'Searching...';
            numberText.textContent = phoneInput.value;
            searchBtn.disabled = true;
            searchBtn.textContent = '⏳ Searching...';
        }
        
        function showSuccess(data) {
            resultBox.className = 'result-box success';
            resultBox.innerHTML = `
                <div class="status-badge success">✅ Success</div>
                <pre>${JSON.stringify(data.data, null, 2)}</pre>
            `;
            statusText.textContent = '✅ Success';
            numberText.textContent = data.number || phoneInput.value;
            searchBtn.disabled = false;
            searchBtn.textContent = '🔍 Search';
        }
        
        function showError(message, data = null) {
            resultBox.className = 'result-box error';
            let html = `
                <div class="status-badge error">❌ Error</div>
                <p style="margin: 8px 0; font-size: 14px;"><strong>Message:</strong> ${escapeHtml(message)}</p>
            `;
            if (data && data.status) {
                html += `<p style="margin: 4px 0; font-size: 14px;"><strong>Status:</strong> ${data.status}</p>`;
            }
            if (data && data.debug) {
                html += `<pre style="margin-top: 10px; font-size: 12px; background: rgba(0,0,0,0.05); padding: 10px; border-radius: 8px;">${escapeHtml(JSON.stringify(data.debug, null, 2))}</pre>`;
            }
            resultBox.innerHTML = html;
            statusText.textContent = '❌ Error';
            numberText.textContent = phoneInput.value;
            searchBtn.disabled = false;
            searchBtn.textContent = '🔍 Search';
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Check URL for initial number
        const urlParams = new URLSearchParams(window.location.search);
        const initialNumber = urlParams.get('number');
        if (initialNumber) {
            phoneInput.value = initialNumber;
            performSearch();
        }
    </script>
</body>
</html>