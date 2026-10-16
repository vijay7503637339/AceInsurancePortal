<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use AcePortal\\Auth;
$error=null;$portal=(string)($_GET['portal']??$_POST['portal']??'customer');if(!in_array($portal,['customer','agency'],true))$portal='customer';
if($_SERVER['REQUEST_METHOD']==='POST'){verifyCsrf();$portal=(string)($_POST['portal']??'customer');$portal=in_array($portal,['customer','agency'],true)?$portal:'customer';if(Auth::attempt((string)($_POST['email']??''),(string)($_POST['password']??''),$portal))redirect($portal==='agency'?'/agency/index.php':'/customer/index.php');$error='Invalid email/password, or your account is not allowed on this login tab.';}
$flash=getFlash();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ace Insurance Portal — Sign In</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><div class="auth-card">
<header class="auth-header"><div class="shield">◆</div><h1>Ace Insurance</h1><p>Sign in to your account</p></header>
<div class="auth-tabs"><a class="<?= $portal==='customer'?'active':'' ?>" href="?portal=customer">♙ Customer Login</a><a class="<?= $portal==='agency'?'active':'' ?>" href="?portal=agency">▦ Agency Login</a></div>
<div class="auth-body"><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><?php if($flash):?><div class="alert <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endif;?>
<form method="post"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="portal" value="<?=e($portal)?>"><label>Email Address<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn primary full">Sign In</button></form>
<?php if($portal==='customer'):?><div class="auth-links"><a href="/auth/signup.php">Create Account</a><a href="/auth/forgot-password.php">Forgot Password?</a></div><?php else:?><p class="muted small">Agency admins and staff sign in here. Staff cannot access admin activity logs.</p><?php endif;?></div></div></body></html>
