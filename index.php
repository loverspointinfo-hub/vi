<?php
/**
 * CallApp API Integration
 * Fetches caller information from CallApp service
 * 
 * API Endpoint: /callapp-api.php?number=919872678971
 * Method: GET
 * Response: JSON
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set JSON response header
header('Content-Type: application/json');

// CORS headers (optional - configure as needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Fetch CallApp information for a given phone number
 * 
 * @param string $number Phone number with country code (e.g., 919872678971)
 * @return array Response with status and data/error
 */
function fetchCallAppInfo($number) {
    // Base URL
    $baseUrl = "https://s.callapp.com/callapp-server/csrch";
    
    // Clean the number - remove any non-numeric characters except '+'
    $cleanedNumber = preg_replace('/[^0-9+]/', '', $number);
    
    // Ensure number starts with + if not already
    if (strpos($cleanedNumber, '+') !== 0) {
        $cleanedNumber = '+' . $cleanedNumber;
    }
    
    // Parameters from the original Python script
    $params = [
        'cpn' => $cleanedNumber,
        'myp' => 'fb.877409278562861',
        'ibs' => '0',
        'cid' => '0',
        'tk' => '0080528975',
        'cvc' => '2268'
    ];
    
    // Build query string
    $queryString = http_build_query($params);
    $fullUrl = $baseUrl . '?' . $queryString;
    
    // Headers from the original Python script
    $headers = [
        'Host: s.callapp.com',
        'User-Agent: Mozilla/5.0 (Linux; Android 12; ONEPLUS A6013 Build/SQ3A.220705.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/108.0.5359.128 Mobile Safari/537.36',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive'
    ];
    
    // Initialize cURL
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL => $fullUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true, // Set to false in development if needed
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '' // Handle all encodings including gzip, deflate, br
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    // Close cURL
    curl_close($ch);
    
    // Check for cURL errors
    if ($curlError) {
        return [
            'success' => false,
            'status' => 500,
            'error' => 'cURL Error: ' . $curlError,
            'number' => $cleanedNumber
        ];
    }
    
    // Process response based on status code
    switch ($httpCode) {
        case 200:
            // Decode JSON response
            $decodedResponse = json_decode($response, true);
            
            // Check if response is valid JSON
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'success' => true,
                    'status' => 200,
                    'data' => $decodedResponse,
                    'number' => $cleanedNumber,
                    'raw_response' => $response // Optional - remove for production
                ];
            } else {
                // Response is not JSON
                return [
                    'success' => true,
                    'status' => 200,
                    'data' => $response,
                    'number' => $cleanedNumber,
                    'raw_response' => $response
                ];
            }
            
        case 401:
            return [
                'success' => false,
                'status' => 401,
                'error' => 'Unauthorized - Token expired or invalid',
                'number' => $cleanedNumber
            ];
            
        default:
            return [
                'success' => false,
                'status' => $httpCode,
                'error' => 'Request failed with status code: ' . $httpCode,
                'response' => $response,
                'number' => $cleanedNumber
            ];
    }
}

/**
 * Validate phone number format
 * 
 * @param string $number Phone number to validate
 * @return bool|string Returns cleaned number or false if invalid
 */
function validatePhoneNumber($number) {
    // Remove all non-numeric characters except '+'
    $cleaned = preg_replace('/[^0-9+]/', '', $number);
    
    // Check if number has at least 10 digits (without country code)
    $digitsOnly = preg_replace('/[^0-9]/', '', $cleaned);
    
    if (strlen($digitsOnly) < 10) {
        return false;
    }
    
    return $cleaned;
}

// --- MAIN API HANDLER ---

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use GET method.',
        'allowed_methods' => ['GET']
    ]);
    exit();
}

// Get and validate phone number parameter
$number = isset($_GET['number']) ? trim($_GET['number']) : '';

if (empty($number)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Phone number is required',
        'example' => '/callapp-api.php?number=919872678971',
        'format' => 'Number with country code (e.g., 919872678971 or +919872678971)'
    ]);
    exit();
}

// Validate phone number format
$validatedNumber = validatePhoneNumber($number);

if ($validatedNumber === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid phone number format',
        'message' => 'Please provide a valid phone number with at least 10 digits',
        'provided' => $number
    ]);
    exit();
}

// Fetch information
$result = fetchCallAppInfo($validatedNumber);

// Set appropriate HTTP status code
http_response_code($result['status'] ?? 500);

// Output result as JSON
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Log the request (optional - enable if needed)
// error_log("CallApp API Request: " . $validatedNumber . " - Status: " . ($result['status'] ?? 'unknown'));

?>