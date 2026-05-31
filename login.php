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

if ($databaseError === null && $pdo && toolboxCurrentUser($pdo)) {
    toolboxRedirect('index.php');
}

$redirect = toolboxSanitizeRedirect($_REQUEST['redirect'] ?? 'index.php', 'index.php');
$flash = toolboxConsumeFlash();

if ($databaseError === null && $pdo && !toolboxHasAnyUsers($pdo)) {
    toolboxRedirect('setup_admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseError === null && $pdo) {
    if (!toolboxVerifyCsrfToken($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Enter your email and password.';
        } elseif (toolboxIsLoginBlocked($pdo, $email)) {
            $error = 'Too many failed login attempts. Please wait 15 minutes.';
        } else {
            $user = toolboxLoadUserByEmail($pdo, $email);
            if (!$user || (int)($user['is_active'] ?? 0) !== 1 || !toolboxPasswordVerify($password, (string)$user['password_hash'])) {
                toolboxRecordLoginAttempt($pdo, $email, false, 'password_failed');
                $error = 'Invalid email or password.';
            } else {
                toolboxSetPendingAuth($user, $redirect);
                toolboxAudit($pdo, (int)$user['id'], 'password_verified', ['redirect' => $redirect]);
                if ((int)($user['mfa_enabled'] ?? 0) === 1 && trim((string)($user['mfa_secret'] ?? '')) !== '') {
                    toolboxRedirect('mfa_verify.php');
                }
                toolboxRedirect('mfa_setup.php');
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login · <?php echo htmlspecialchars(toolboxAppName(), ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/forms.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell auth-shell">
    <div class="panel auth-panel">
      <div class="panel-head">
        <h1 class="auth-title"><?php echo htmlspecialchars(toolboxAppName(), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Sign in with your password and continue with MFA.</p>
      </div>
      <div class="panel-body">
        <?php if ($databaseError !== null): ?>
          <div class="flash error">Database error: <?php echo htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($flash): ?>
          <div class="flash <?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="flash error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
          <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(toolboxCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">
          <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" autocomplete="username" required>
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
          </div>
          <div class="button-group auth-actions">
            <button class="button primary" type="submit">Continue</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
