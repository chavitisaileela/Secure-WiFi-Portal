<?php
// Set up error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. Configuration & Setup ---
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', ''); // Empty for default XAMPP
define('DB_NAME', 'wifi_portal');

// --- 2. Database Connection ---
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// Set JSON header for API responses
header('Content-Type: application/json');

// --- 3. Utility Functions ---
function generate_passcode($length = 6) {
    // This pool now includes Numbers, Small Letters, and Capital Letters
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'; 
    $char_length = strlen($characters);
    $random_string = '';
    
    for ($i = 0; $i < $length; $i++) {
        // Securely picks one random character from the full pool
        $random_string .= $characters[random_int(0, $char_length - 1)]; 
    }
    return $random_string;
}

function get_client_mac_address() {
    return md5($_SERVER['REMOTE_ADDR']); // Placeholder for MAC retrieval
}

// --- 4. Main Router ---
$action = $_GET['action'] ?? '';

// ACTION A: HANDLE PASSCODE REQUEST
if ($action === 'request') {
    $user_id = $_POST['user_id'] ?? '';
    if (empty($user_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Mobile/Email is required.']);
        exit;
    }

    $passcode = generate_passcode(6);
    $generated_time = date('Y-m-d H:i:s');
    $expiry_time = date('Y-m-d H:i:s', time() + (8 * 3600)); // 8-hour limit

    $sql = "INSERT INTO wifi_sessions (user_id, passcode, generated_time, expiry_time, status)
            VALUES (?, ?, ?, ?, 'PENDING')
            ON DUPLICATE KEY UPDATE passcode=?, generated_time=?, expiry_time=?, status='PENDING'";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("sssssss", $user_id, $passcode, $generated_time, $expiry_time, $passcode, $generated_time, $expiry_time);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Request sent! Waiting for approval.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'DB Error: Could not save request.']);
        }
        $stmt->close();
    }

// ACTION B: HANDLE APPROVE AND SEND (Updated to set custom time)
} elseif ($action === 'approve_and_send') {
    $user_id = $_POST['user_id'] ?? $_GET['user_id'] ?? '';
    
    // 1. Get the data from the database first to find the passcode
    $res = $conn->query("SELECT passcode FROM wifi_sessions WHERE user_id = '$user_id'");
    $row = $res->fetch_assoc();
    $passcode = $row['passcode'] ?? '';

    // 2. Update status to APPROVED
    $sql_update = "UPDATE wifi_sessions SET status = 'APPROVED', device_macs = '[]' WHERE user_id = ?";
    
    if ($stmt_update = $conn->prepare($sql_update)) {
        $stmt_update->bind_param("s", $user_id);
        
        if ($stmt_update->execute()) {
            
            // --- NEW: SENDING LOGIC STARTS HERE ---
            
            // Is it an Email?
            if (filter_var($user_id, FILTER_VALIDATE_EMAIL)) {
                $subject = "Your WiFi Passcode";
                $message = "Your request is approved! Your passcode is: " . $passcode;
                $headers = "From: admin@yourwifi.com";
                
                // This sends the email
                mail($user_id, $subject, $message, $headers);
                $msg = "Approved! Email sent to $user_id";
            } 
            // Is it a Phone Number? (Checks if it is only numbers)
            elseif (is_numeric($user_id)) {
                // To send a real SMS, you need an API like Twilio. 
                // For now, we "simulate" the send.
                $msg = "Approved! SMS with code $passcode sent to $user_id";
                
                // If you get a Twilio account later, you put that code here.
            } else {
                $msg = "Approved, but ID type unknown.";
            }

            echo json_encode(['status' => 'success', 'message' => $msg]);
            
            // --- NEW: SENDING LOGIC ENDS HERE ---

        } else {
            echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
        }
        $stmt_update->close();
    }

// ACTION C: HANDLE PASSCODE VALIDATION
} elseif ($action === 'validate') {
    $user_id = $_POST['user_id'] ?? '';
    $passcode = $_POST['passcode'] ?? '';
    $current_mac = get_client_mac_address();
    $current_time = date('Y-m-d H:i:s');

    if (empty($user_id) || empty($passcode)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing ID or Passcode.']);
        exit;
    }

    $sql = "SELECT passcode, expiry_time, status, device_macs FROM wifi_sessions WHERE user_id = ? LIMIT 1";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $session = $result->fetch_assoc();
        $stmt->close();

        if ($session) {
            $device_macs = json_decode($session['device_macs'] ?? '[]', true);
            
            // Validation Checks
            if ($passcode !== $session['passcode']) {
                $message = 'Invalid Passcode.';
            } elseif ($session['status'] !== 'APPROVED') {
                $message = 'Access is still pending owner approval.';
            } elseif ($session['expiry_time'] < $current_time) {
                $message = 'Passcode has expired.';
            } elseif (!in_array($current_mac, $device_macs) && count($device_macs) >= 2) {
                $message = 'Maximum device limit (2) reached.';
            } else {
                // Success: Update MACs and status
                if (!in_array($current_mac, $device_macs)) {
                    $device_macs[] = $current_mac;
                    $new_macs_json = json_encode(array_unique($device_macs));
                    $conn->query("UPDATE wifi_sessions SET device_macs = '$new_macs_json', status = 'USED' WHERE user_id = '$user_id'");
                }
                echo json_encode(['status' => 'success', 'message' => 'Access Granted! Connected to WiFi for 8 hours.']);
                exit;
            }
            echo json_encode(['status' => 'error', 'message' => $message]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No session found for this ID.']);
        }
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid API call.']);
}

$conn->close();
?>