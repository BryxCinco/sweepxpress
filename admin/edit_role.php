<?php
// Include necessary files and establish database connection
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

// Set the content type for JSON response
header('Content-Type: application/json');

// Helper function to send a JSON response and exit
function send_json_response($success, $message, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Check if the user is an admin
if (!is_admin()) {
    send_json_response(false, "Access denied. Admin privileges required.", 403);
}

// Check if the request is a POST request and contains the necessary data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id']) && isset($_POST['role'])) {
    $userId = $_POST['user_id'];
    $newRole = $_POST['role'];

    // Validate the new role against a list of allowed roles
    $validRoles = ['customer', 'admin', 'business']; //
    
    if (!in_array($newRole, $validRoles)) {
        send_json_response(false, "Invalid role specified.", 400);
    }

    try {
        // Prepare the SQL statement to update the user's role
        $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE id = :id");

        // Bind the parameters to the prepared statement
        $stmt->bindParam(':role', $newRole, PDO::PARAM_STR);
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);

        // Execute the statement
        $stmt->execute();

        // Check if the update was successful (if any rows were affected)
        if ($stmt->rowCount() > 0) {
            // SUCCESS RESPONSE: HTTP 200 OK
            send_json_response(true, "Role updated successfully!");
        } else {
            // NO CHANGES RESPONSE: Still successful execution, HTTP 200 OK
            send_json_response(true, "No changes made. The user ID might not exist or the role is already the same.");
        }
    } catch (PDOException $e) {
        // Handle database-related errors: HTTP 500 Internal Server Error
        send_json_response(false, "Database error: " . $e->getMessage(), 500);
    }
} else {
    // Return a bad request error if the request method or data is invalid: HTTP 400 Bad Request
    send_json_response(false, "Invalid request. User ID or role not provided.", 400);
}
?>