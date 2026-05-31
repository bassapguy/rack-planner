<?php
require_once __DIR__ . '/bootstrap.php';

$pending = toolboxRequirePendingAuth();
$pdo = db();
$user = toolboxLoadUserById($pdo, (int)$pending['user_id']);
if (!$user) {
    toolboxClearPendingAuth();
    toolboxFlash('error', 'The login session expired. Please sign in again.');
    toolboxRedirect('login.php');
}

if ((int)($user['mfa_enabled'] ?? 0) !== 1 || trim((string)($user['mfa_secret'] ?? '')) === '') {
    toolboxRedirect('mfa_setup.php');
}

$flash = toolboxConsumeFlash();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!toolboxVerifyCsrfToken($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $code = (string)($_POST['code'] ?? '');
        if (!toolboxVerifyTotpCode((string)$user['mfa_secret'], $code)) {
            toolboxRecordLoginAttempt($pdo, (string)$user['email'], false, 'mfa_failed');
            toolboxAudit($pdo, (int)$user['id'], 'mfa_failed');
            $error = 'The MFA code is not valid. Please try again.';
        } else {
            toolboxRecordLoginAttempt($pdo, (string)$user['email'], true, 'login_success');
            toolboxAudit($pdo, (int)$user['id'], 'login_success');
            toolboxCompleteLogin($pdo, $user);
            toolboxFlash('success', 'Welcome back.');
            toolboxRedirect((string)($pending['redirect'] ?? 'index.php'));
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verify MFA · <?php echo htmlspecialchars(toolboxAppName(), ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/forms.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell auth-shell">
    <div class="panel auth-panel">
      <div class="panel-head">
        <h1 class="auth-title">Verify MFA</h1>
        <p>Enter the 6-digit code from your authenticator app.</p>
      </div>
      <div class="panel-body">
        <?php if ($flash): ?>
          <div class="flash <?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="flash error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" action="mfa_verify.php">
          <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(toolboxCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
          <div class="field">
            <label for="code">MFA code</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
          </div>
          <div class="button-group auth-actions">
            <button class="button primary" type="submit">Verify</button>
            <a class="button secondary" href="login.php">Start over</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
