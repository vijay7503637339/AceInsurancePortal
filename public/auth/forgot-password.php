<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

$error = null;
$notice = null;
$stage = (string)($_SESSION['password_reset_stage'] ?? 'identify');
$context = $_SESSION['password_reset_context'] ?? null;
$oldEmail = trim((string)($_POST['email'] ?? ($context['email'] ?? '')));
$oldDot = trim((string)($_POST['dot_number'] ?? ($context['dot_number'] ?? '')));

if (!in_array($stage, ['identify', 'otp', 'password'], true) || !is_array($context)) {
    if ($stage !== 'identify') {
        unset($_SESSION['password_reset_stage'], $_SESSION['password_reset_context']);
    }
    $stage = 'identify';
    $context = null;
}

if (isset($_GET['restart'])) {
    unset($_SESSION['password_reset_stage'], $_SESSION['password_reset_context']);
    $stage = 'identify';
    $context = null;
    $oldEmail = '';
    $oldDot = '';
}

function sendCustomerPasswordResetOtp(string $to, string $code): bool
{
    $mailConfig = configFile('mail');
    $from = trim((string)($mailConfig['from_email'] ?? ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Email delivery is not configured. Please contact the agency.');
    }
    $fromName = str_replace(["\r", "\n", '"'], '', (string)($mailConfig['from_name'] ?? 'ACE Insurance Portal'));
    $subject = 'Your ACE Insurance password reset code';
    $body = "We received a request to reset the password for your ACE Insurance customer account.\n\n"
        . "Your one-time password (OTP) is: " . $code . "\n\n"
        . "This code expires in 10 minutes and can be used only once. You have a limited number of attempts. "
        . "If you did not request this reset, you can ignore this email.\n";
    $headers = [
        'From: ' . sprintf('%s <%s>', $fromName, $from),
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    return mail($to, $subject, $body, implode("\r\n", $headers));
}

function issueCustomerPasswordResetOtp(int $userId, string $email, string $dotNumber): array
{
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $emailLimit = db()->prepare('SELECT COUNT(*) FROM password_reset_otps WHERE email=:email AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
    $emailLimit->execute(['email' => $email]);
    if ((int)$emailLimit->fetchColumn() >= 3) {
        throw new RuntimeException('Too many OTP requests for this email. Please try again later.');
    }

    if ($ip !== '') {
        $ipLimit = db()->prepare('SELECT COUNT(*) FROM password_reset_otps WHERE request_ip=:ip AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
        $ipLimit->execute(['ip' => $ip]);
        if ((int)$ipLimit->fetchColumn() >= 10) {
            throw new RuntimeException('Too many OTP requests from this connection. Please try again later.');
        }
    }

    db()->prepare('UPDATE password_reset_otps SET used_at=UTC_TIMESTAMP() WHERE user_id=:user_id AND used_at IS NULL')
        ->execute(['user_id' => $userId]);

    $code = (string)random_int(100000, 999999);
    $insert = db()->prepare(
        'INSERT INTO password_reset_otps(user_id,email,dot_number,otp_hash,expires_at,request_ip)
         VALUES(:user_id,:email,:dot_number,:otp_hash,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE),:request_ip)'
    );
    $insert->execute([
        'user_id' => $userId,
        'email' => $email,
        'dot_number' => $dotNumber,
        'otp_hash' => password_hash($code, PASSWORD_DEFAULT),
        'request_ip' => $ip !== '' ? $ip : null,
    ]);
    $resetId = (int)db()->lastInsertId();

    try {
        if (!sendCustomerPasswordResetOtp($email, $code)) {
            throw new RuntimeException('OTP email could not be sent. Please try later or contact the agency.');
        }
    } catch (Throwable $exception) {
        db()->prepare('UPDATE password_reset_otps SET used_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id' => $resetId]);
        throw $exception;
    }

    return ['id' => $resetId, 'user_id' => $userId, 'email' => $email, 'dot_number' => $dotNumber];
}

function maskRecoveryEmail(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $visible = mb_substr($name, 0, min(2, mb_strlen($name)));
    return $visible . str_repeat('*', max(2, mb_strlen($name) - mb_strlen($visible))) . '@' . $parts[1];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'start_reset') {
            $now = time();
            $lookupAttempts = array_values(array_filter(
                (array)($_SESSION['password_reset_lookup_attempts'] ?? []),
                static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 900
            ));
            if (count($lookupAttempts) >= 10) {
                throw new RuntimeException('Too many attempts. Please wait a few minutes and try again.');
            }
            $lookupAttempts[] = $now;
            $_SESSION['password_reset_lookup_attempts'] = $lookupAttempts;

            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $dotNumber = preg_replace('/\s+/', '', trim((string)($_POST['dot_number'] ?? ''))) ?? '';
            if ($dotNumber === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address and DOT number.');
            }

            $query = db()->prepare(
                "SELECT u.id, u.email, i.dot_number
                 FROM users u
                 INNER JOIN insureds i ON i.id=u.insured_id
                 WHERE u.role='customer' AND u.is_active=1 AND i.status='active'
                   AND LOWER(TRIM(u.email))=:user_email
                   AND LOWER(TRIM(i.email))=:insured_email
                   AND REPLACE(i.dot_number,' ','')=:dot_number
                 LIMIT 1"
            );
            $query->execute(['user_email' => $email, 'insured_email' => $email, 'dot_number' => $dotNumber]);
            $account = $query->fetch();
            if (!$account) {
                throw new RuntimeException('We could not verify those details. Check the email and DOT number or contact your agency.');
            }

            $newContext = issueCustomerPasswordResetOtp((int)$account['id'], $email, $dotNumber);
            session_regenerate_id(true);
            $_SESSION['password_reset_context'] = $newContext;
            $_SESSION['password_reset_stage'] = 'otp';
            $stage = 'otp';
            $context = $newContext;
            $oldEmail = $email;
            $oldDot = $dotNumber;
            $notice = 'A six-digit OTP has been sent to ' . maskRecoveryEmail($email) . '. It expires in 10 minutes.';
        } elseif ($action === 'resend_otp') {
            $context = $_SESSION['password_reset_context'] ?? null;
            if (!is_array($context) || (string)($_SESSION['password_reset_stage'] ?? '') !== 'otp') {
                throw new RuntimeException('Start password recovery again.');
            }
            $newContext = issueCustomerPasswordResetOtp((int)$context['user_id'], (string)$context['email'], (string)$context['dot_number']);
            $_SESSION['password_reset_context'] = $newContext;
            $_SESSION['password_reset_stage'] = 'otp';
            $stage = 'otp';
            $context = $newContext;
            $notice = 'A new OTP has been sent to ' . maskRecoveryEmail((string)$newContext['email']) . '. The previous code is no longer valid.';
        } elseif ($action === 'verify_otp') {
            $context = $_SESSION['password_reset_context'] ?? null;
            if (!is_array($context) || (string)($_SESSION['password_reset_stage'] ?? '') !== 'otp') {
                throw new RuntimeException('Start password recovery again.');
            }

            $code = trim((string)($_POST['otp'] ?? ''));
            if (!preg_match('/^[0-9]{6}$/', $code)) {
                throw new RuntimeException('Enter the six-digit OTP from your email.');
            }

            $query = db()->prepare(
                'SELECT * FROM password_reset_otps
                 WHERE id=:id AND user_id=:user_id AND email=:email AND dot_number=:dot_number
                   AND used_at IS NULL AND expires_at>UTC_TIMESTAMP()
                 LIMIT 1'
            );
            $query->execute([
                'id' => (int)$context['id'],
                'user_id' => (int)$context['user_id'],
                'email' => (string)$context['email'],
                'dot_number' => (string)$context['dot_number'],
            ]);
            $reset = $query->fetch();
            if (!$reset) {
                throw new RuntimeException('This OTP has expired or is no longer valid. Use Resend OTP to request a new code.');
            }
            if ((int)$reset['attempts'] >= 5) {
                throw new RuntimeException('Too many incorrect attempts. Use Resend OTP to request a new code.');
            }
            if (!password_verify($code, (string)$reset['otp_hash'])) {
                db()->prepare('UPDATE password_reset_otps SET attempts=attempts+1 WHERE id=:id')->execute(['id' => (int)$reset['id']]);
                throw new RuntimeException('Incorrect OTP. Please check the code and try again.');
            }

            db()->prepare(
                'UPDATE password_reset_otps
                 SET verified_at=UTC_TIMESTAMP()
                 WHERE id=:id AND used_at IS NULL AND verified_at IS NULL AND expires_at>UTC_TIMESTAMP()'
            )->execute(['id' => (int)$reset['id']]);
            $_SESSION['password_reset_stage'] = 'password';
            $stage = 'password';
            $notice = 'Email verified. Create a new password for your account.';
        } elseif ($action === 'reset_password') {
            $context = $_SESSION['password_reset_context'] ?? null;
            if (!is_array($context) || (string)($_SESSION['password_reset_stage'] ?? '') !== 'password') {
                throw new RuntimeException('Verify the email OTP before setting a new password.');
            }

            $password = (string)($_POST['password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            if (mb_strlen($password) < 12 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                throw new RuntimeException('Use at least 12 characters, including at least one letter and one number.');
            }
            if (!hash_equals($password, $confirmPassword)) {
                throw new RuntimeException('The passwords do not match.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $query = $pdo->prepare(
                    "SELECT r.id AS reset_id, u.id AS user_id
                     FROM password_reset_otps r
                     INNER JOIN users u ON u.id=r.user_id
                     INNER JOIN insureds i ON i.id=u.insured_id
                     WHERE r.id=:reset_id AND r.user_id=:reset_user_id AND r.email=:reset_email
                       AND r.dot_number=:reset_dot_number AND r.verified_at IS NOT NULL
                       AND r.used_at IS NULL AND r.expires_at>UTC_TIMESTAMP()
                       AND u.role='customer' AND u.is_active=1 AND i.status='active'
                       AND LOWER(TRIM(i.email))=:insured_email
                       AND REPLACE(i.dot_number,' ','')=:insured_dot
                     LIMIT 1 FOR UPDATE"
                );
                $query->execute([
                    'reset_id' => (int)$context['id'],
                    'reset_user_id' => (int)$context['user_id'],
                    'reset_email' => (string)$context['email'],
                    'reset_dot_number' => (string)$context['dot_number'],
                    'insured_email' => (string)$context['email'],
                    'insured_dot' => (string)$context['dot_number'],
                ]);
                $reset = $query->fetch();
                if (!$reset) {
                    throw new RuntimeException('The OTP verification has expired. Please start password recovery again.');
                }

                $pdo->prepare("UPDATE users SET password_hash=:hash WHERE id=:id AND role='customer' AND is_active=1")
                    ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int)$reset['user_id']]);
                $pdo->prepare('UPDATE password_reset_otps SET used_at=UTC_TIMESTAMP() WHERE id=:id')
                    ->execute(['id' => (int)$reset['reset_id']]);
                $pdo->prepare('UPDATE password_reset_otps SET used_at=UTC_TIMESTAMP() WHERE user_id=:user_id AND id<>:id AND used_at IS NULL')
                    ->execute(['user_id' => (int)$reset['user_id'], 'id' => (int)$reset['reset_id']]);
                $pdo->commit();

                try {
                    audit('password_reset', 'user', (int)$reset['user_id'], 'Customer reset password after email OTP verification');
                } catch (Throwable $auditException) {
                    error_log('Password reset succeeded but audit logging failed: ' . $auditException->getMessage());
                }

                unset($_SESSION['password_reset_context'], $_SESSION['password_reset_stage'], $_SESSION['password_reset_lookup_attempts']);
                flash('success', 'Password updated successfully. Sign in using your new password.');
                redirect('/login.php?portal=customer');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
        } else {
            throw new RuntimeException('Invalid password recovery action. Please start again.');
        }
    } catch (Throwable $exception) {
        error_log('Customer password recovery request failed: ' . $exception->getMessage());
        $error = $exception instanceof PDOException
            ? 'Password recovery is temporarily unavailable. Please try later or contact the agency.'
            : $exception->getMessage();
        $stage = (string)($_SESSION['password_reset_stage'] ?? 'identify');
        $context = $_SESSION['password_reset_context'] ?? null;
        $oldEmail = trim((string)($_POST['email'] ?? ($context['email'] ?? '')));
        $oldDot = trim((string)($_POST['dot_number'] ?? ($context['dot_number'] ?? '')));
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password · ACE Insurance</title>
<link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>">
</head>
<body class="auth-page agency-auth-page">
<div class="agency-auth-layout">
  <?php require __DIR__ . '/_brand-panel.php'; ?>
  <div class="auth-card agency-auth-card auth-recovery-card">
    <header class="auth-header">
      <div class="shield" aria-hidden="true">◆</div>
      <h1>Forgot Password?</h1>
      <p>Recover your customer account securely</p>
    </header>
    <div class="auth-recovery-steps">
      <div class="<?=$stage === 'identify' ? 'current' : ($stage === 'otp' || $stage === 'password' ? 'done' : '')?>"><span>1</span><small>Verify details</small></div>
      <div class="<?=$stage === 'otp' ? 'current' : ($stage === 'password' ? 'done' : '')?>"><span>2</span><small>Email OTP</small></div>
      <div class="<?=$stage === 'password' ? 'current' : ''?>"><span>3</span><small>New password</small></div>
    </div>
    <div class="auth-body">
      <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert success"><?=e($notice)?></div><?php endif; ?>

      <?php if ($stage === 'identify'): ?>
        <p class="auth-step-intro">Enter the email address and DOT number registered with your agency.</p>
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
          <input type="hidden" name="action" value="start_reset">
          <label>Email Address<input type="email" name="email" value="<?=e($oldEmail)?>" required autocomplete="email"></label>
          <label>DOT Number<input type="text" name="dot_number" value="<?=e($oldDot)?>" required autocomplete="off" maxlength="24"></label>
          <button class="btn primary full" type="submit">Send OTP</button>
        </form>
      <?php elseif ($stage === 'otp'): ?>
        <p class="auth-step-intro">Enter the six-digit code sent to <strong><?=e(maskRecoveryEmail((string)($context['email'] ?? '')))?></strong>.</p>
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
          <input type="hidden" name="action" value="verify_otp">
          <label>Email OTP<input class="otp-input" type="text" name="otp" required pattern="[0-9]{6}" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000"></label>
          <button class="btn primary full" type="submit">Verify OTP</button>
        </form>
        <form method="post" class="auth-resend-form">
          <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
          <input type="hidden" name="action" value="resend_otp">
          <button class="btn secondary full" type="submit">Resend OTP</button>
        </form>
        <div class="auth-links center"><a href="<?=e(appUrl('/auth/forgot-password.php?restart=1'))?>">Start over</a></div>
      <?php else: ?>
        <p class="auth-step-intro">Email verified. Set a strong new password with at least 12 characters, including a letter and a number.</p>
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
          <input type="hidden" name="action" value="reset_password">
          <label>New Password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
          <label>Confirm New Password<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label>
          <button class="btn primary full" type="submit">Update Password</button>
        </form>
        <div class="auth-links center"><a href="<?=e(appUrl('/auth/forgot-password.php?restart=1'))?>">Start over</a></div>
      <?php endif; ?>

      <div class="auth-links center"><a href="<?=e(appUrl('/login.php?portal=customer'))?>">← Back to Login</a></div>
    </div>
  </div>
</div>
</body>
</html>
