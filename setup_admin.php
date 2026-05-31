<?php
require_once __DIR__ . '/bootstrap.php';

$databaseError = null;
$error = '';
$pdo = null;

try {
    $pdo = db();
} catch (Throwable $e) {
    $databaseError = $e->getMessage();
}

if ($databaseError === null && $pdo && toolboxHasAnyUsers($pdo)) {
    if (toolboxCurrentUser($pdo)) {
        toolboxRedirect('index.php');
    }
    toolboxRedirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseError === null && $pdo) {
    if (!toolboxVerifyCsrfToken($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if ($fullName === '' || $email === '' || $password === '' || $passwordConfirm === '') {
            $error = 'Fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 12) {
            $error = 'Use a password with at least 12 characters.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'The passwords do not match.';
        } else {
            $userId = toolboxCreateUser($pdo, [
                'email' => $email,
                'full_name' => $fullName,
                'password_hash' => toolboxPasswordHash($password),
                'role' => 'super_admin',
            ]);
            $user = toolboxLoadUserById($pdo, $userId);
            toolboxAudit($pdo, $userId, 'bootstrap_admin_created', ['email' => $email]);
            toolboxSetPendingAuth($user ?: ['id' => $userId], 'index.php');
            toolboxFlash('success', 'Admin account created. Set up MFA to continue.');
            toolboxRedirect('mfa_setup.php');
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>First admin setup · <?php echo htmlspecialchars(toolboxAppName(), ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/forms.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell auth-shell">
    <div class="panel auth-panel">
      <div class="panel-head">
        <h1 class="auth-title">First admin setup</h1>
        <p>Create the first super admin account for the toolbox.</p>
      </div>
      <div class="panel-body">
        <?php if ($databaseError !== null): ?>
          <div class="flash error">Database error: <?php echo htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="flash error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" action="setup_admin.php">
          <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(toolboxCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
          <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" required>
          </div>
          <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required>
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            <small class="help">Use at least 12 characters.</small>
          </div>
          <div class="field">
            <label for="password_confirm">Repeat password</label>
            <input type="password" id="password_confirm" name="password_confirm" required>
          </div>
          <div class="button-group auth-actions">
            <button class="button primary" type="submit">Create admin account</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
