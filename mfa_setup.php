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

$existingSecret = trim((string)($user['mfa_secret'] ?? ''));
$secret = $existingSecret !== '' ? $existingSecret : (toolboxPendingMfaSecret() ?: toolboxGenerateBase32Secret());
if ($existingSecret === '') {
    toolboxSetPendingMfaSecret($secret);
}

$flash = toolboxConsumeFlash();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!toolboxVerifyCsrfToken($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $code = (string)($_POST['code'] ?? '');
        if (!toolboxVerifyTotpCode($secret, $code)) {
            $error = 'The MFA code is not valid. Please try again.';
        } else {
            toolboxEnableMfa($pdo, (int)$user['id'], $secret);
            $updatedUser = toolboxLoadUserById($pdo, (int)$user['id']);
            toolboxAudit($pdo, (int)$user['id'], 'mfa_enabled');
            toolboxRecordLoginAttempt($pdo, (string)$user['email'], true, 'mfa_enabled');
            toolboxCompleteLogin($pdo, $updatedUser ?: $user);
            toolboxFlash('success', 'MFA enabled successfully.');
            toolboxRedirect((string)($pending['redirect'] ?? 'index.php'));
        }
    }
}

$otpauthUri = toolboxBuildOtpAuthUri((string)$user['email'], $secret);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>MFA setup · <?php echo htmlspecialchars(toolboxAppName(), ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="stylesheet" href="assets/css/base.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/base.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/buttons.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/buttons.css') ?: time(); ?>">
  <link rel="stylesheet" href="assets/css/forms.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/forms.css') ?: time(); ?>">
</head>
<body>
  <main class="page-shell auth-shell">
    <div class="panel auth-panel">
      <div class="panel-head">
        <h1 class="auth-title">Set up MFA</h1>
        <p>MFA is required for every user. Add this secret to your authenticator app and enter the current 6-digit code.</p>
      </div>
      <div class="panel-body">
        <?php if ($flash): ?>
          <div class="flash <?php echo htmlspecialchars((string)$flash['type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="flash error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="panel auth-info-panel">
          <div class="panel-head">
            <h2>Authenticator details</h2>
          </div>
          <div class="panel-body auth-details">
            <div><strong>Account:</strong> <?php echo htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Secret:</strong> <code><?php echo htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div><strong>OTP URI:</strong> <code class="auth-code-block"><?php echo htmlspecialchars($otpauthUri, ENT_QUOTES, 'UTF-8'); ?></code></div>
          </div>
        </div>

        <form method="post" action="mfa_setup.php">
          <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(toolboxCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
          <div class="field">
            <label for="code">MFA code</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
          </div>
          <div class="button-group auth-actions">
            <button class="button primary" type="submit">Activate MFA</button>
            <a class="button secondary" href="login.php">Start over</a>
          </div>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
