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
   $dot=preg_replace('/\\s+/','',trim((string)($_POST['dot_number']??'')));
   $email=mb_strtolower(trim((string)($_POST['email']??'')));
   if($dot===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a DOT number and valid email address.');
   $q=db()->prepare("SELECT id,email FROM insureds WHERE dot_number=:dot AND status='active' LIMIT 1");
   $q->execute(['dot'=>$dot]);$insured=$q->fetch();
   if(!$insured||!$insured['email']||!hash_equals(mb_strtolower($insured['email']),$email))throw new RuntimeException('DOT and email do not match our records. Contact the agency.');
   $q=db()->prepare("SELECT id FROM users WHERE insured_id=:insured AND role='customer' LIMIT 1");$q->execute(['insured'=>$insured['id']]);
   if($q->fetch())throw new RuntimeException('Account already exists. Please sign in.');
   $code=(string)random_int(100000,999999);
   $q=db()->prepare('INSERT INTO signup_verifications(insured_id,email,code_hash,expires_at) VALUES(:insured,:email,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))');
   $q->execute(['insured'=>$insured['id'],'email'=>$email,'hash'=>password_hash($code,PASSWORD_DEFAULT)]);
   $vid=(int)db()->lastInsertId();
   if(!sendVerificationEmail($email,$code))throw new RuntimeException('Email could not be sent. Ask the agency to configure email delivery.');
   $_SESSION['signup_stage']='email';$_SESSION['signup_context']=['insured_id'=>(int)$insured['id'],'email'=>$email,'verification_id'=>$vid];$stage='email';
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
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create Account</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><div class="auth-card wide"><header class="auth-header"><div class="shield">◆</div><h1>Create Account</h1><p>American Trucking Solutions</p></header><div class="steps"><span class="<?=$stage==='dot'?'current':''?>">1<br><small>Verify DOT</small></span><span class="<?=$stage==='email'?'current':''?>">2<br><small>Verify Email</small></span><span class="<?=$stage==='password'?'current':''?>">3<br><small>Set Password</small></span></div><div class="auth-body"><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<?php if($stage==='dot'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="verify_dot"><label>DOT Number<input name="dot_number" required></label><label>Email Address<input type="email" name="email" required></label><p class="muted small">DOT number and email must match an active insured record entered by your agency.</p><button class="btn primary full">Verify DOT & Continue</button></form><?php elseif($stage==='email'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="verify_email"><label>Six-digit verification code<input name="code" required pattern="[0-9]{6}" maxlength="6"></label><button class="btn primary full">Verify Email</button></form><?php else:?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="create_password"><label>Create Password<input type="password" name="password" minlength="12" required></label><label>Confirm Password<input type="password" name="confirm_password" minlength="12" required></label><button class="btn primary full">Create Account</button></form><?php endif;?><div class="auth-links center"><a href="/login.php">← Back to Login</a></div></div></div></body></html>
