<?php
// admin_register.php
session_start();
require_once 'config.php';

// If already logged in, redirect to admin panel
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_lucky.php');
    exit;
}

$register_error = '';
$register_success = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $register_error = 'All fields are required!';
    } elseif (strlen($username) < 3) {
        $register_error = 'Username must be at least 3 characters!';
    } elseif (strlen($password) < 6) {
        $register_error = 'Password must be at least 6 characters!';
    } elseif ($password !== $password_confirm) {
        $register_error = 'Passwords do not match!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = 'Invalid email address!';
    } else {
        // Check if username already exists
        $checkStmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? OR email = ?");
        $checkStmt->execute([$username, $email]);
        if ($checkStmt->fetch()) {
            $register_error = 'Username or email already exists!';
        } else {
            // Register new admin
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $pdo->prepare("INSERT INTO admin_users (username, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$username, $email, $hashedPassword]);
                $register_success = 'Registration successful! You can now <a href="admin_lucky.php">log in here</a>.';
            } catch (Exception $e) {
                $register_error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<?php public_head('Admin register'); ?>
<div class="wrap auth-card">
    <?php brand_block('Register Admin'); ?>
    <div class="card">
        <?php if (!empty($register_error)): ?>
            <div class="alert alert-error"><?php echo e($register_error); ?></div>
        <?php endif; ?>

        <?php if (!empty($register_success)): ?>
            <div class="alert alert-success"><?php echo $register_success; ?></div>
        <?php else: ?>
            <form method="POST">
                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required minlength="3" autocomplete="username" value="<?php echo e($_POST['username'] ?? ''); ?>">
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required autocomplete="email" value="<?php echo e($_POST['email'] ?? ''); ?>">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="At least 6 characters" required minlength="6" autocomplete="new-password">
                </div>
                <div class="field">
                    <label for="password_confirm">Confirm password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="6" autocomplete="new-password">
                </div>
                <button class="btn btn-primary btn-block" type="submit">Create account</button>
            </form>
            <p class="text-center muted mt-2" style="font-size:14px">Already have an account? <a href="admin_lucky.php">Log in</a></p>
        <?php endif; ?>
    </div>
</div>
<?php public_foot(); ?>
