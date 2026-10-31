<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
$stage=(string)($_SESSION['signup_stage']??'dot');
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();
 $action=(string)($_POST['action']??'');
 try {
  if($action==='verify_dot'){
   // Step 1 only verifies the DOT and fetches the registered email. No OTP is sent here.
   $dot=preg_replace('/\\s+/','',trim((string)($_POST['dot_number']??'')));
   if($dot==='')throw new RuntimeException('Enter your DOT number.');

   // Clear any previously verified DOT before processing a new verification request.
   unset($_SESSION['signup_context']);
   $_SESSION['signup_stage']='dot';
   $stage='dot';

   $q=db()->prepare("SELECT id,dot_number,email FROM insureds WHERE dot_number=:dot AND status='active' LIMIT 1");
   $q->execute(['dot'=>$dot]);
   $insured=$q->fetch();
   if(!$insured)throw new RuntimeException('DOT number was not found in active insured records. Please contact your agency.');

   $registeredEmail=mb_strtolower(trim((string)($insured['email']??'')));
   if(!filter_var($registeredEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('No valid email address is saved for this DOT number. Please contact your agency.');

   $q=db()->prepare("SELECT id FROM users WHERE insured_id=:insured AND role='customer' LIMIT 1");
   $q->execute(['insured'=>$insured['id']]);
   if($q->fetch())throw new RuntimeException('Account already exists. Please sign in.');

   // Keep the verified DOT/email on the server; the browser cannot change this session data.
   $_SESSION['signup_context']=[
    'insured_id'=>(int)$insured['id'],
    'dot_number'=>$dot,
    'email'=>$registeredEmail,
    'verified_at'=>time()
   ];
   $stage='dot';
  } elseif($action==='continue_signup') {
   // Continue is allowed only for the exact DOT that was verified in this session.
   $c=$_SESSION['signup_context']??null;
   $dot=preg_replace('/\\s+/','',trim((string)($_POST['dot_number']??'')));
   $verifiedAt=is_array($c)?(int)($c['verified_at']??0):0;
   if(!$c||($_SESSION['signup_stage']??'')!=='dot'||$dot===''||!hash_equals((string)($c['dot_number']??''),$dot)||$verifiedAt<1||$verifiedAt>time()||time()-$verifiedAt>900){
    unset($_SESSION['signup_context']);
    throw new RuntimeException('Please verify your DOT number again before continuing.');
   }

   // Re-check the current agency record so a changed/disabled record cannot continue on stale data.
   $q=db()->prepare("SELECT id,dot_number,email FROM insureds WHERE id=:id AND dot_number=:dot AND status='active' LIMIT 1");
   $q->execute(['id'=>(int)$c['insured_id'],'dot'=>$dot]);
   $insured=$q->fetch();
   $registeredEmail=$insured?mb_strtolower(trim((string)($insured['email']??''))):'';
   if(!$insured||!filter_var($registeredEmail,FILTER_VALIDATE_EMAIL)||!hash_equals((string)$c['email'],$registeredEmail)){
    unset($_SESSION['signup_context']);
    throw new RuntimeException('The insured record or registered email has changed. Please verify the DOT again or contact your agency.');
   }

   $q=db()->prepare("SELECT id FROM users WHERE insured_id=:insured AND role='customer' LIMIT 1");
   $q->execute(['insured'=>$insured['id']]);
   if($q->fetch())throw new RuntimeException('Account already exists. Please sign in.');

   // Existing email OTP flow starts only after the customer clicks Continue.
   $code=(string)random_int(100000,999999);
   $q=db()->prepare('INSERT INTO signup_verifications(insured_id,email,code_hash,expires_at) VALUES(:insured,:email,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))');
   $q->execute(['insured'=>$insured['id'],'email'=>$registeredEmail,'hash'=>password_hash($code,PASSWORD_DEFAULT)]);
   $vid=(int)db()->lastInsertId();
   if(!sendVerificationEmail($registeredEmail,$code))throw new RuntimeException('Email could not be sent. Ask the agency to configure email delivery.');
   $_SESSION['signup_stage']='email';
   $_SESSION['signup_context']=['insured_id'=>(int)$insured['id'],'email'=>$registeredEmail,'verification_id'=>$vid];
   $stage='email';
  } elseif($action==='verify_email') {
   $c=$_SESSION['signup_context']??null;if(!$c||($_SESSION['signup_stage']??'')!=='email')throw new RuntimeException('Start signup again.');
   $q=db()->prepare('SELECT * FROM signup_verifications WHERE id=:id AND insured_id=:insured AND email=:email AND verified_at IS NULL AND expires_at>UTC_TIMESTAMP()');$q->execute(['id'=>$c['verification_id'],'insured'=>$c['insured_id'],'email'=>$c['email']]);$v=$q->fetch();
   if(!$v||(int)$v['attempts']>=5)throw new RuntimeException('Code expired or too many attempts. Start again.');
   if(!password_verify(trim((string)($_POST['code']??'')),$v['code_hash'])){db()->prepare('UPDATE signup_verifications SET attempts=attempts+1 WHERE id=:id')->execute(['id'=>$v['id']]);throw new RuntimeException('Incorrect verification code.');}
   db()->prepare('UPDATE signup_verifications SET verified_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$v['id']]);$_SESSION['signup_stage']='password';$stage='password';
  } elseif($action==='create_password') {
   $c=$_SESSION['signup_context']??null;if(!$c||($_SESSION['signup_stage']??'')!=='password')throw new RuntimeException('Complete email verification first.');
   $p=(string)($_POST['password']??'');$p2=(string)($_POST['confirm_password']??'');
   if(strlen($p)<12||!preg_match('/[A-Za-z]/',$p)||!preg_match('/[0-9]/',$p))throw new RuntimeException('Use 12+ characters including letters and numbers.');
   if(!hash_equals($p,$p2))throw new RuntimeException('Passwords do not match.');
   $q=db()->prepare('SELECT id FROM signup_verifications WHERE id=:id AND insured_id=:insured AND email=:email AND verified_at IS NOT NULL AND expires_at>UTC_TIMESTAMP()');$q->execute(['id'=>$c['verification_id'],'insured'=>$c['insured_id'],'email'=>$c['email']]);if(!$q->fetch())throw new RuntimeException('Verification expired. Start again.');
   $q=db()->prepare('SELECT legal_name FROM insureds WHERE id=:id AND email=:email AND status="active"');$q->execute(['id'=>$c['insured_id'],'email'=>$c['email']]);$insured=$q->fetch();if(!$insured)throw new RuntimeException('Insured record changed. Contact the agency.');
   $q=db()->prepare('INSERT INTO users(role,full_name,email,password_hash,insured_id,email_verified_at) VALUES("customer",:name,:email,:hash,:insured,UTC_TIMESTAMP())');$q->execute(['name'=>$insured['legal_name'],'email'=>$c['email'],'hash'=>password_hash($p,PASSWORD_DEFAULT),'insured'=>$c['insured_id']]);
   audit('signup','user',(int)db()->lastInsertId(),'Customer registered after DOT and email verification');unset($_SESSION['signup_stage'],$_SESSION['signup_context']);flash('success','Account created. Please sign in.');redirect('/login.php?portal=customer');
  }
 } catch(Throwable $ex){$error=$ex instanceof PDOException&&(string)$ex->getCode()==='23000'?'Account already exists. Please sign in or start again.':$ex->getMessage();$stage=(string)($_SESSION['signup_stage']??'dot');}
}

// A verified DOT is valid for 15 minutes and is bound to server-side session data.
$signupContext=$_SESSION['signup_context']??null;
$verifiedAt=is_array($signupContext)?(int)($signupContext['verified_at']??0):0;
$dotVerified=$stage==='dot'&&is_array($signupContext)&&$verifiedAt>0&&$verifiedAt<=time()&&time()-$verifiedAt<=900;
$dotInputValue=trim((string)($_POST['dot_number']??($dotVerified?($signupContext['dot_number']??''):'')));
$registeredEmailValue=$dotVerified?(string)($signupContext['email']??''):'';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Account · ACE Insurance</title>
<link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>">
</head>
<body class="auth-page agency-auth-page">
<div class="agency-auth-layout">
  <?php require __DIR__ . '/_brand-panel.php'; ?>
  <div class="auth-card agency-auth-card signup-auth-card">
    <header class="auth-header">
      <div class="shield" aria-hidden="true">◆</div>
      <h1>Create Account</h1>
      <p>American Trucking Solutions</p>
    </header>
    <div class="auth-recovery-steps">
      <div class="<?=$stage==='dot'?'current':($stage==='email'||$stage==='password'?'done':'')?>"><span>1</span><small>Verify details</small></div>
      <div class="<?=$stage==='email'?'current':($stage==='password'?'done':'')?>"><span>2</span><small>Email OTP</small></div>
      <div class="<?=$stage==='password'?'current':''?>"><span>3</span><small>Set password</small></div>
    </div>
    <div class="auth-body">
      <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>

      <?php if ($stage === 'dot'): ?>
        <p class="auth-step-intro">Enter your DOT number and verify it against the active insured record maintained by your agency.</p>
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
          <div style="display:flex;gap:10px;align-items:flex-end">
            <div style="flex:1;min-width:0">
              <label for="signup-dot-number">DOT Number</label>
              <input id="signup-dot-number" name="dot_number" value="<?=e($dotInputValue)?>" required autocomplete="off" maxlength="24">
            </div>
            <button class="btn secondary" type="submit" name="action" value="verify_dot" style="white-space:nowrap">Verify</button>
          </div>
          <?php if ($dotVerified): ?>
            <label for="signup-registered-email">Registered Email Address
              <input id="signup-registered-email" type="email" name="email" value="<?=e($registeredEmailValue)?>" readonly aria-readonly="true" autocomplete="off">
            </label>
            <p class="muted small">DOT verified. This email was fetched from your agency record and cannot be edited.</p>
            <button class="btn primary full" type="submit" name="action" value="continue_signup">Continue</button>
          <?php else: ?>
            <p class="muted small">After DOT verification, the registered email address will appear here. You will not be able to edit it.</p>
          <?php endif; ?>
        </form>
      <?php elseif ($stage === 'email'): ?>
        <p class="auth-step-intro">Enter the six-digit verification code sent to your registered email address.</p>
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
          <input type="hidden" name="action" value="verify_email">
          <label>Six-digit verification code<input class="otp-input" name="code" required pattern="[0-9]{6}" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000"></label>
          <button class="btn primary full" type="submit">Verify Email</button>
        </form>
      <?php else: ?>
        <p class="auth-step-intro">Create a strong password with at least 12 characters, including letters and numbers.</p>
        <form method="post" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
          <input type="hidden" name="action" value="create_password">
          <label>Create Password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
          <label>Confirm Password<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password"></label>
          <button class="btn primary full" type="submit">Create Account</button>
        </form>
      <?php endif; ?>

      <div class="auth-links center"><a href="<?=e(appUrl('/login.php?portal=customer'))?>">← Back to Login</a></div>
    </div>
  </div>
</div>
</body>
</html>
