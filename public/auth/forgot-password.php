<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Password Recovery</title><link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>"></head><body class="auth-page"><div class="auth-card"><header class="auth-header"><h1>Password Recovery</h1><p>Secure account recovery</p></header><div class="auth-body"><div class="alert info">The password reset table is ready. The full email-reset flow will be enabled after confirming delivery requirements.</div><a class="btn primary full" href="<?=e(appUrl('/login.php'))?>">Back to Login</a></div></div></body></html>
