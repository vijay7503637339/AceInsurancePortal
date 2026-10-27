<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;

$user = Auth::requireRoles(['admin','staff']);
$error = null;
$flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please complete all password fields.';
    } elseif (mb_strlen($newPassword) < 12) {
        $error = 'Your new password must contain at least 12 characters.';
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $error = 'The new password and confirmation do not match.';
    } else {
        $query = db()->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $query->execute(['id' => (int)$user['id']]);
        $account = $query->fetch();

        if (!$account || empty($account['password_hash']) || !password_verify($currentPassword, (string)$account['password_hash'])) {
            $error = 'Your current password is incorrect.';
        } elseif (password_verify($newPassword, (string)$account['password_hash'])) {
            $error = 'Choose a new password that is different from your current password.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND is_active = 1');
            $update->execute(['password_hash' => $newHash, 'id' => (int)$user['id']]);

            session_regenerate_id(true);
            try {
                audit('password_change', 'user', (int)$user['id'], 'User changed their account password');
            } catch (\Throwable $e) {
                error_log('Password changed but audit logging failed: '.$e->getMessage());
            }
            flash('success', 'Your password has been changed successfully.');
            redirect('/agency/change-password.php');
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Change Password · ACE Insurance</title>
<link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>">
</head>
<body>
<header class="topbar">
  <div><strong>ACE Insurance</strong><span>Agency Portal</span></div>
  <div class="topbar-account">
    <span class="topbar-user"><?=e($user['full_name'])?> · <?=e($user['role'])?></span>
    <a class="btn light small-btn" href="<?=e(appUrl('/agency/change-password.php'))?>" aria-current="page">Change Password</a>
    <a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a>
  </div>
</header>
<div class="layout">
  <aside class="sidebar">
    <a href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a>
    <a href="<?=e(appUrl('/agency/finance.php'))?>">Finance Billing Manage</a>
    <a href="<?=e(appUrl('/agency/claims.php'))?>">Claims Management</a>
    <?php foreach (['insureds'=>'Insured Customers','vehicles'=>'Vehicles','policies'=>'Policies','drivers'=>'Drivers'] as $key=>$label): ?>
      <a href="<?=e(appUrl('/agency/crud.php?entity='.$key))?>"><?=e($label)?></a>
    <?php endforeach; ?>
    <?php if ($user['role'] === 'admin'): ?>
      <a href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a>
      <a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a>
      <a href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a>
    <?php endif; ?>
  </aside>
  <main class="main change-password-main">
    <div class="page-title">
      <div><h1>Change Password</h1><p class="muted">Update the password for your ACE Insurance account.</p></div>
    </div>
    <?php if ($flash): ?><div class="alert success"><?=e((string)$flash['message'])?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
    <section class="panel change-password-panel">
      <div class="change-password-heading">
        <div class="change-password-icon" aria-hidden="true">♙</div>
        <div><h2>Account security</h2><p class="muted">Use at least 12 characters. A unique passphrase is recommended.</p></div>
      </div>
      <form method="post" class="change-password-form" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
        <label>Current Password
          <input type="password" name="current_password" autocomplete="current-password" required>
        </label>
        <label>New Password
          <input type="password" name="new_password" autocomplete="new-password" minlength="12" required>
        </label>
        <label>Confirm New Password
          <input type="password" name="confirm_password" autocomplete="new-password" minlength="12" required>
        </label>
        <div class="change-password-help">Your current password is required to confirm this change.</div>
        <div class="form-actions"><button class="btn primary" type="submit">Update Password</button><a class="btn light" href="<?=e(appUrl('/agency/index.php'))?>">Cancel</a></div>
      </form>
    </section>
  </main>
</div>
</body>
</html>
