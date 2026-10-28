<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use AcePortal\Auth;

$error = null;
$portal = (string)($_GET['portal'] ?? $_POST['portal'] ?? 'customer');
if (!in_array($portal, ['customer', 'agency'], true)) $portal = 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $portal = (string)($_POST['portal'] ?? 'customer');
    $portal = in_array($portal, ['customer', 'agency'], true) ? $portal : 'customer';
    if (Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), $portal)) {
        redirect($portal === 'agency' ? '/agency/index.php' : '/customer/index.php');
    }
    $error = 'Invalid credentials, or account not allowed on this login tab.';
}
$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ace Insurance Portal — Sign In</title>
<link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>">
</head>
<body class="auth-page <?=$portal === 'agency' ? 'agency-auth-page' : 'customer-auth-page'?>">
<div class="<?=$portal === 'agency' ? 'agency-auth-layout' : 'auth-single-layout'?>">
<?php if ($portal === 'agency'): ?>
  <aside class="agency-auth-brand" aria-label="Agency portal overview">
    <div class="agency-brand-top">
      <span class="agency-brand-symbol" aria-hidden="true">A</span>
      <div class="agency-brand-wordmark">
        <strong>ACE <span>INSURANCE</span></strong>
        <small>AGENCY PORTAL</small>
      </div>
    </div>

    <div class="agency-brand-copy">
      <span class="agency-brand-eyebrow">INSURANCE OPERATIONS</span>
      <h1>Confidence in every policy.</h1>
      <p>Your agency workspace for managing clients, coverage, claims and billing — all in one secure place.</p>
    </div>

    <div class="agency-feature-list">
      <div class="agency-feature">
        <span class="agency-feature-icon" aria-hidden="true">01</span>
        <div><strong>Policy management</strong><small>Keep insureds, vehicles and coverage organized.</small></div>
      </div>
      <div class="agency-feature">
        <span class="agency-feature-icon" aria-hidden="true">02</span>
        <div><strong>Claims &amp; finance</strong><small>Follow claims and billing activity in one workspace.</small></div>
      </div>
      <div class="agency-feature">
        <span class="agency-feature-icon" aria-hidden="true">03</span>
        <div><strong>Secure agency access</strong><small>Role-based access with activity tracking.</small></div>
      </div>
    </div>

    <div class="agency-brand-footer"><span class="agency-secure-dot"></span> SECURE AGENCY ACCESS <span class="agency-footer-divider">•</span> ACE INSURANCE</div>
  </aside>
<?php endif; ?>

  <div class="auth-card <?=$portal === 'agency' ? 'agency-auth-card' : ''?>">
    <header class="auth-header">
      <div class="shield" aria-hidden="true">◆</div>
      <h1>Ace Insurance</h1>
      <p>Sign in to your account</p>
    </header>
    <div class="auth-tabs">
      <a class="<?=$portal === 'customer' ? 'active' : ''?>" href="?portal=customer">♙ Customer Login</a>
      <a class="<?=$portal === 'agency' ? 'active' : ''?>" href="?portal=agency">▦ Agency Login</a>
    </div>
    <div class="auth-body">
      <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
      <?php if ($flash): ?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
        <input type="hidden" name="portal" value="<?=e($portal)?>">
        <label>Email Address<input type="email" name="email" required autocomplete="username"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn primary full" type="submit">Sign In</button>
      </form>
      <?php if ($portal === 'customer'): ?>
        <div class="auth-links">
          <a href="<?=e(appUrl('/auth/signup.php'))?>">Create Account</a>
          <a href="<?=e(appUrl('/auth/forgot-password.php'))?>">Forgot Password?</a>
        </div>
      <?php else: ?>
        <p class="muted small">Agency administrators and staff use this login. Staff cannot access admin activity logs.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
