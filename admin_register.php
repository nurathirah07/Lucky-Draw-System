<?php
// admin_register.php - Creates an admin account.
// Closed to the public: open only for the first account, or while
// $allow_admin_register is switched on in config.php (see adminRegistrationOpen()).
session_start();
require_once 'config.php';
require_once 'admin_auth.php';

// If already logged in, redirect to admin panel
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_lucky.php');
    exit;
}

$register_error = '';
$register_success = '';

if (!adminRegistrationOpen($pdo)) {
    public_page_start('Registration closed', 'Admin registration closed', 'en');
    ?>
    <div class="card">
        <?php status_block('wait', 'New admin accounts cannot be created here', 'Admin accounts are added by the system owner. If you already have an account, sign in.'); ?>
    </div>
    <?php
    public_actions([['Sign in', 'admin_lucky.php', 'arrow', true]]);
    public_page_end(['Customer page' => 'index.php']);
    exit;
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $register_error = 'All fields are required.';
    } elseif (strlen($username) < 3) {
        $register_error = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 6) {
        $register_error = 'Password must be at least 6 characters.';
    } elseif ($password !== $password_confirm) {
        $register_error = 'The passwords do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = 'Enter a valid email address.';
    } else {
        // Check if username already exists
        $checkStmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? OR email = ?");
        $checkStmt->execute([$username, $email]);
        if ($checkStmt->fetch()) {
            $register_error = 'That username or email is already in use.';
        } else {
            // Register new admin. The lock + re-check stops two people creating the
            // "first" admin at the same moment.
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $pdo->query("SELECT GET_LOCK('astech_admin_register', 10)");
            try {
                if (!adminRegistrationOpen($pdo)) {
                    $register_error = 'Admin registration is closed.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO admin_users (username, email, password) VALUES (?, ?, ?)");
                    $stmt->execute([$username, $email, $hashedPassword]);
                    $register_success = true;
                }
            } catch (Exception $e) {
                $register_error = 'Registration failed. Please try again.';
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('astech_admin_register')");
            }
        }
    }
}
?>
<?php public_page_start('Create admin account', 'Create admin account', 'en'); ?>
<div class="card">
    <?php if (!empty($register_success)): ?>
        <?php status_block('ok', 'Account created', 'You can now sign in with your username and password.'); ?>
        <div class="form-actions"><a class="btn btn-primary btn-block" href="admin_lucky.php">Sign in</a></div>
    <?php else: ?>
        <?php if (!empty($register_error)): ?><div style="margin-bottom:16px"><?php echo alert_box('error', e($register_error)); ?></div><?php endif; ?>
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
                <input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
                <div class="hint">At least 6 characters</div>
            </div>
            <div class="field">
                <label for="password_confirm">Confirm password</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-actions"><button class="btn btn-primary btn-block" type="submit">Create account</button></div>
        </form>
    <?php endif; ?>
</div>
<p class="small muted" style="text-align:center">Already have an account? <a class="link" href="admin_lucky.php">Sign in</a></p>
<?php public_page_end(['Customer page' => 'index.php']); ?>
