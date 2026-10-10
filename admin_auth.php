<?php
// admin_auth.php
// Helper functions for admin authentication

/**
 * Check if admin is logged in
 * Returns true if logged in, false otherwise
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Redirect to login if not authenticated
 * Used to protect admin pages
 */
function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: admin_lucky.php');
        exit;
    }
}

/**
 * Validate admin credentials against database
 * Returns array with success/error message and user data if successful
 */
function validateAdminCredentials($username, $password, $pdo) {
    $result = [
        'success' => false,
        'message' => '',
        'user' => null
    ];

    if (empty($username) || empty($password)) {
        $result['message'] = 'Enter your username and password.';
        return $result;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, email, password FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $result['success'] = true;
            $result['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email']
            ];
        } else {
            $result['message'] = 'Incorrect username or password.';
        }
    } catch (Exception $e) {
        $result['message'] = 'Authentication error. Please try again.';
    }

    return $result;
}

/**
 * Set admin session after successful login
 */
function setAdminSession($user) {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_email'] = $user['email'];
}

/**
 * Get current logged-in admin
 * Returns user data or null if not logged in
 */
function getCurrentAdmin() {
    if (isAdminLoggedIn()) {
        return [
            'id' => $_SESSION['admin_id'] ?? null,
            'username' => $_SESSION['admin_username'] ?? null,
            'email' => $_SESSION['admin_email'] ?? null
        ];
    }
    return null;
}

/**
 * Logout current admin
 */
function logoutAdmin() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Can a new admin account be created on admin_register.php?
 * Only for the first account (empty system), or while $allow_admin_register is
 * switched on in config.php to add another admin.
 */
function adminRegistrationOpen($pdo) {
    global $allow_admin_register;
    if (!empty($allow_admin_register)) return true;
    return (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn() === 0;
}
?>