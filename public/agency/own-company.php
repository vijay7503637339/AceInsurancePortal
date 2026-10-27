<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;
$admin=Auth::requireRoles(['admin']);$error=null;$flash=getFlash();
$defaults=['company_name'=>'','company_address'=>'','contact_person'=>'','phone_number'=>'','email_address'=>'','authorized_signature_name'=>'','authorized_signature_file'=>'','updated_at'=>null];
$settings=db()->query('SELECT * FROM own_company_settings WHERE id=1')->fetch() ?: $defaults;
$oldSignature=(string)($settings['authorized_signature_file']??'');
if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$newSignaturePath=null;$data=[];
 try{
  $data=['company_name'=>trim(substr((string)($_POST['company_name']??''),0,180)),'company_address'=>trim(substr((string)($_POST['company_address']??''),0,2000)),'contact_person'=>trim(substr((string)($_POST['contact_person']??''),0,180)),'phone_number'=>trim(substr((string)($_POST['phone_number']??''),0,60)),'email_address'=>strtolower(trim(substr((string)($_POST['email_address']??''),0,254))),'authorized_signature_name'=>trim(substr((string)($_POST['authorized_signature_name']??''),0,180))];
  foreach(['company_name'=>'Company Name','company_address'=>'Company Address','contact_person'=>'Contact Person','phone_number'=>'Phone Number','email_address'=>'Email Address','authorized_signature_name'=>'Authorized Signatory Name'] as $f=>$label)if($data[$f]==='')throw new \RuntimeException($label.' is required.');
  if(!filter_var($data['email_address'],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('Enter a valid company email address.');
  $signatureFile=$oldSignature;$remove=(string)($_POST['remove_signature']??'')==='1';$upload=$_FILES['authorized_signature_file']??null;$hasUpload=is_array($upload)&&(int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
  if($hasUpload){
   if((int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \RuntimeException('The signature upload did not complete.');
   $tmp=(string)($upload['tmp_name']??'');$size=(int)($upload['size']??0);if($size<1||$size>2*1024*1024||!is_uploaded_file($tmp))throw new \RuntimeException('Signature image must be valid and no larger than 2 MB.');
   $image=@getimagesize($tmp);$mime=is_array($image)?(string)($image['mime']??''):'';
   $ext=match($mime){'image/png'=>'png','image/jpeg'=>'jpg',default=>''};if($ext==='')throw new \RuntimeException('Upload the signature as a PNG or JPEG image.');
   if((int)($image[0]??0)>5000||(int)($image[1]??0)>3000)throw new \RuntimeException('Signature image dimensions are too large.');
   $dir=APP_ROOT.'/storage/private/company-signatures';if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new \RuntimeException('Could not create private signature storage.');
   $signatureFile=bin2hex(random_bytes(16)).'.'.$ext;$newSignaturePath=$dir.'/'.$signatureFile;if(!move_uploaded_file($tmp,$newSignaturePath))throw new \RuntimeException('Could not store the signature image.');@chmod($newSignaturePath,0640);
  }elseif($remove)$signatureFile='';
  $q=db()->prepare('INSERT INTO own_company_settings (id,company_name,company_address,contact_person,phone_number,email_address,authorized_signature_name,authorized_signature_file,updated_by) VALUES (1,:company_name,:company_address,:contact_person,:phone_number,:email_address,:authorized_signature_name,:authorized_signature_file,:updated_by) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name),company_address=VALUES(company_address),contact_person=VALUES(contact_person),phone_number=VALUES(phone_number),email_address=VALUES(email_address),authorized_signature_name=VALUES(authorized_signature_name),authorized_signature_file=VALUES(authorized_signature_file),updated_by=VALUES(updated_by)');
  $q->execute(['company_name'=>$data['company_name'],'company_address'=>$data['company_address'],'contact_person'=>$data['contact_person'],'phone_number'=>$data['phone_number'],'email_address'=>$data['email_address'],'authorized_signature_name'=>$data['authorized_signature_name'],'authorized_signature_file'=>$signatureFile!==''?$signatureFile:null,'updated_by'=>(int)$admin['id']]);
  if($oldSignature!==''&&$oldSignature!==$signatureFile&&preg_match('/^[a-f0-9]{32}\.(?:png|jpg)$/',$oldSignature)){$oldPath=APP_ROOT.'/storage/private/company-signatures/'.$oldSignature;if(is_file($oldPath))@unlink($oldPath);}
  audit('update','own_company_settings',1,'Updated own company profile and certificate producer details');flash('success','Own Company details saved successfully.');redirect('/agency/own-company.php');
 }catch(\Throwable $ex){if(is_string($newSignaturePath)&&is_file($newSignaturePath))@unlink($newSignaturePath);error_log('Own Company save failed: '.$ex->getMessage());$error=$ex instanceof \RuntimeException?$ex->getMessage():'Could not save Own Company details. Check the server error log.';$settings=array_merge($settings,$data);$settings['authorized_signature_file']=$oldSignature;}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Own Company · ACE Insurance</title><link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>"></head><body>
<header class="topbar"><div><strong>ACE Insurance</strong><span>Agency Portal</span></div><div><?=e($admin['full_name'])?> · Admin <a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a></div></header>
<div class="layout"><aside class="sidebar"><a href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a><a href="<?=e(appUrl('/agency/finance.php'))?>">Finance Billing Manage</a><a href="<?=e(appUrl('/agency/claims.php'))?>">Claims Management</a><?php foreach(['insureds'=>'Insured Customers','vehicles'=>'Vehicles','policies'=>'Policies','drivers'=>'Drivers'] as $k=>$label):?><a href="<?=e(appUrl('/agency/crud.php?entity='.$k))?>"><?=e($label)?></a><?php endforeach;?><a class="selected" href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a><a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a><a href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a></aside>
<main class="main own-company-main"><div class="page-title"><div><h1>Own Company</h1><p class="muted">Company details used on generated insurance certificates.</p></div></div>
<?php if($flash):?><div class="alert success"><?=e((string)$flash['message'])?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<section class="panel own-company-panel"><div class="own-company-intro"><span class="own-company-icon">▣</span><div><h2>Company Details</h2><p class="muted">These details populate Producer, Contact and Authorized Representative fields on the ACORD certificate.</p></div></div>
<form method="post" enctype="multipart/form-data" class="own-company-form"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><div class="own-company-grid">
<label>Company Name *<input type="text" name="company_name" maxlength="180" required value="<?=e((string)($settings['company_name']??''))?>" placeholder="Legal company name"></label>
<label>Contact Person *<input type="text" name="contact_person" maxlength="180" required value="<?=e((string)($settings['contact_person']??''))?>" placeholder="Certificate contact name"></label>
<label class="own-company-wide">Company Address *<textarea name="company_address" rows="3" maxlength="2000" required placeholder="Street address, city, state and ZIP"><?=e((string)($settings['company_address']??''))?></textarea></label>
<label>Contact Phone Number *<input type="tel" name="phone_number" maxlength="60" required value="<?=e((string)($settings['phone_number']??''))?>" placeholder="+1 000 000 0000"></label>
<label>Company Email Address *<input type="email" name="email_address" maxlength="254" required value="<?=e((string)($settings['email_address']??''))?>" placeholder="certificates@example.com"></label>
<label>Authorized Signatory Name *<input type="text" name="authorized_signature_name" maxlength="180" required value="<?=e((string)($settings['authorized_signature_name']??''))?>" placeholder="Authorized representative's name"></label>
<label class="own-company-wide">Authorized Signature Image <input type="file" name="authorized_signature_file" accept=".png,.jpg,.jpeg,image/png,image/jpeg"><small>Optional PNG/JPEG image, maximum 2 MB; stored privately and added to the certificate PDF.</small></label>
</div><?php if(!empty($settings['authorized_signature_file'])):?><div class="signature-status"><span class="status-pill is-active">Signature image saved</span><span class="muted small"><?=e((string)$settings['authorized_signature_file'])?></span><label class="signature-remove"><input type="checkbox" name="remove_signature" value="1"> Remove saved signature image</label></div><?php endif;?>
<div class="form-actions"><button class="btn primary" type="submit">Save Own Company Details</button></div></form></section>
<section class="panel own-company-info"><h2>Certificate Mapping</h2><div class="own-company-mapping"><div><strong>Producer</strong><span>Company name and address</span></div><div><strong>Contact</strong><span>Contact person, phone and email</span></div><div><strong>Authorized Representative</strong><span>Signature image and signatory name</span></div></div></section>
</main></div></body></html>