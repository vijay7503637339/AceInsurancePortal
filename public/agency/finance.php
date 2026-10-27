<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;
$u=Auth::requireRoles(['admin','staff']);
$base='/agency/finance.php';
$statuses=['Current','Past Due','Cancellation Pending','Paid Off','Cancelled','Pending Setup'];
$types=['AL','APD','MTC','GL','TI','NT','Package / Multiple','Other'];
$companies=['Avian Premium Finance Company','FIRST Insurance Funding','National Partners','Honor Capital (ETI Financial)','IPFS Premium Finance Company','GBC Premium Finance Company','Other / Direct Bill / New Finance Co.'];
$autopayOptions=['Yes','No','Pending'];$paymentMethods=['ACH','Card','Wire','Check','Finance Draft','Other'];
$fields=[
 ['status','Status','select',$statuses,true,''],['coverage_policy_type','Coverage / Policy Type','select',$types,true,''],
 ['finance_company','Finance Company','select',$companies,true,''],['finance_account_number','Finance Account #','text',[],false,''],
 ['down_payment','Down Payment ($)','number',[],false,'0.01'],['installments','Installments','number',[],false,'1'],
 ['monthly_amount','Monthly Amount ($)','number',[],false,'0.01'],['next_due_date','Next Due Date','date',[],false,''],
 ['pending_past_due','Pending / Past Due ($)','number',[],false,'0.01']
];
$action=(string)($_GET['action']??'list');$error=null;$flash=getFlash();$formId=null;
$form=['insured_id'=>'','policy_id'=>'','status'=>'Pending Setup','coverage_policy_type'=>'','finance_company'=>'','finance_company_other'=>'','finance_account_number'=>'','down_payment'=>'','installments'=>'','monthly_amount'=>'','next_due_date'=>'','pending_past_due'=>'','autopay'=>'','payment_method'=>'','last_payment_date'=>'','last_payment_amount'=>'','billing_notes'=>''];
$insureds=db()->query('SELECT id,legal_name,dot_number,status FROM insureds ORDER BY legal_name')->fetchAll();
$policies=db()->query('SELECT id,insured_id,policy_number,business_line FROM policies ORDER BY policy_number')->fetchAll();
$money=static fn($v)=>$v===null||$v===''||!is_numeric($v)?'—':'$'.number_format((float)$v,2);
$date=static function($v){if(!$v)return '—';$t=strtotime((string)$v);return $t===false?(string)$v:date('M j, Y',$t);};
$companyLabel=static function(array $r){$c=(string)($r['finance_company']??'');$o=trim((string)($r['finance_company_other']??''));return $c==='Other / Direct Bill / New Finance Co.'&&$o!==''?$c.' — '.$o:$c;};
$sel=static fn(string $f,string $v)=>(string)($form[$f]??'')===$v?'selected':'';

if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$postAction=(string)($_POST['action']??'save');$rawId=trim((string)($_POST['id']??''));$id=$rawId!==''?filter_var($rawId,FILTER_VALIDATE_INT):null;
 try{
  if($rawId!==''&&(!$id||$id<1))throw new \RuntimeException('Invalid finance record ID.');
  if($postAction==='delete'){
   if(!$id)throw new \RuntimeException('Finance record ID is missing.');
   $s=db()->prepare('SELECT id FROM finance_billing WHERE id=:id');$s->execute(['id'=>$id]);if(!$s->fetch())throw new \RuntimeException('Finance record not found.');
   db()->prepare('DELETE FROM finance_billing WHERE id=:id')->execute(['id'=>$id]);
   audit('delete','finance_billing',(int)$id,'Deleted Finance Billing record');flash('success','Finance record deleted.');redirect($base);
  }
  $form=[];
  foreach(['insured_id','policy_id','status','coverage_policy_type','finance_company','finance_company_other','finance_account_number','down_payment','installments','monthly_amount','next_due_date','pending_past_due','autopay','payment_method','last_payment_date','last_payment_amount','billing_notes'] as $f)$form[$f]=trim((string)($_POST[$f]??''));
  $form['finance_company_other']=substr($form['finance_company_other'],0,255);$form['finance_account_number']=substr($form['finance_account_number'],0,100);$form['billing_notes']=substr($form['billing_notes'],0,5000);
  $action=$id?'edit':'add';$formId=$id?(int)$id:null;
  $insuredId=filter_var($form['insured_id'],FILTER_VALIDATE_INT);if(!$insuredId||$insuredId<1)throw new \RuntimeException('Select an insured customer.');
  $q=db()->prepare('SELECT id FROM insureds WHERE id=:id');$q->execute(['id'=>$insuredId]);if(!$q->fetch())throw new \RuntimeException('Insured customer not found.');
  $policyId=null;
  if($form['policy_id']!==''){$tmp=filter_var($form['policy_id'],FILTER_VALIDATE_INT);if(!$tmp||$tmp<1)throw new \RuntimeException('Select a valid policy.');$q=db()->prepare('SELECT id,insured_id FROM policies WHERE id=:id');$q->execute(['id'=>$tmp]);$p=$q->fetch();if(!$p||(int)$p['insured_id']!==(int)$insuredId)throw new \RuntimeException('The selected policy must belong to the selected insured customer.');$policyId=(int)$tmp;}
  if(!in_array($form['status'],$statuses,true))throw new \RuntimeException('Select a valid Status.');
  if(!in_array($form['coverage_policy_type'],$types,true))throw new \RuntimeException('Select a Coverage / Policy Type.');
  if(!in_array($form['finance_company'],$companies,true))throw new \RuntimeException('Select a Finance Company.');
  if($form['autopay']!==''&&!in_array($form['autopay'],$autopayOptions,true))throw new \RuntimeException('Select a valid Autopay option.');
  if($form['payment_method']!==''&&!in_array($form['payment_method'],$paymentMethods,true))throw new \RuntimeException('Select a valid Payment Method.');
  $amount=static function(string $v,string $label):?string{if($v==='')return null;if(!is_numeric($v)||(float)$v<0||!is_finite((float)$v))throw new \RuntimeException($label.' must be a non-negative amount.');return number_format((float)$v,2,'.','');};
  $dateInput=static function(string $v,string $label):?string{if($v==='')return null;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new \RuntimeException('Enter a valid '.$label.'.');return $v;};
  $inst=null;if($form['installments']!==''){if(!ctype_digit($form['installments'])||(int)$form['installments']>1000)throw new \RuntimeException('Installments must be a whole number from 0 to 1000.');$inst=(int)$form['installments'];}
  $data=['insured_id'=>(int)$insuredId,'policy_id'=>$policyId,'status'=>$form['status'],'coverage_policy_type'=>$form['coverage_policy_type'],'finance_company'=>$form['finance_company'],'finance_company_other'=>$form['finance_company']==='Other / Direct Bill / New Finance Co.'&&$form['finance_company_other']!==''?$form['finance_company_other']:null,'finance_account_number'=>$form['finance_account_number']!==''?$form['finance_account_number']:null,'down_payment'=>$amount($form['down_payment'],'Down Payment'),'installments'=>$inst,'monthly_amount'=>$amount($form['monthly_amount'],'Monthly Amount'),'next_due_date'=>$dateInput($form['next_due_date'],'Next Due Date'),'pending_past_due'=>$amount($form['pending_past_due'],'Pending / Past Due'),'autopay'=>$form['autopay']!==''?$form['autopay']:null,'payment_method'=>$form['payment_method']!==''?$form['payment_method']:null,'last_payment_date'=>$dateInput($form['last_payment_date'],'Last Payment Date'),'last_payment_amount'=>$amount($form['last_payment_amount'],'Last Payment Amount'),'billing_notes'=>$form['billing_notes']!==''?$form['billing_notes']:null];
  if($id){$q=db()->prepare('SELECT id FROM finance_billing WHERE id=:id');$q->execute(['id'=>$id]);if(!$q->fetch())throw new \RuntimeException('Finance record not found; refresh and retry.');$data['updated_by']=(int)$u['id'];$sets=[];foreach(array_keys($data) as $f)$sets[]=$f.'=:'.$f;$data['id']=(int)$id;db()->prepare('UPDATE finance_billing SET '.implode(',',$sets).' WHERE id=:id')->execute($data);audit('update','finance_billing',(int)$id,'Updated Finance Billing record');flash('success','Finance record updated.');}
  else{$data['created_by']=(int)$u['id'];$data['updated_by']=(int)$u['id'];$cols=array_keys($data);$params=array_map(static fn($f)=>':'.$f,$cols);db()->prepare('INSERT INTO finance_billing ('.implode(',',$cols).') VALUES ('.implode(',',$params).')')->execute($data);audit('create','finance_billing',(int)db()->lastInsertId(),'Created Finance Billing record');flash('success','Finance record added.');}
  redirect($base);
 }catch(\Throwable $ex){error_log('Finance Billing save failed: '.$ex->getMessage());$error=$ex instanceof \RuntimeException?$ex->getMessage():'Could not save the Finance Billing record. Check the server error log.';}
}
if($action==='edit'&&$_SERVER['REQUEST_METHOD']!=='POST'){
 $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);if(!$id||$id<1){http_response_code(400);exit('Invalid finance record ID.');}
 $q=db()->prepare('SELECT * FROM finance_billing WHERE id=:id');$q->execute(['id'=>$id]);$row=$q->fetch();if(!$row){http_response_code(404);exit('Finance record not found.');}$formId=(int)$id;$form=array_merge($form,$row);
}
if(!in_array($action,['list','add','edit'],true))$action='list';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Finance Billing Manage · ACE Insurance</title><link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>"></head><body>
<header class="topbar"><div><strong>ACE Insurance</strong><span>Agency Portal</span></div><div><?=e($u['full_name'])?> · <?=e($u['role'])?> <a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a></div></header>
<div class="layout"><aside class="sidebar"><a href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a><a class="selected" href="<?=e(appUrl($base))?>">Finance Billing Manage</a><?php foreach(['insureds'=>'Insured Customers','vehicles'=>'Vehicles','policies'=>'Policies','drivers'=>'Drivers'] as $k=>$label):?><a href="<?=e(appUrl('/agency/crud.php?entity='.$k))?>"><?=e($label)?></a><?php endforeach;?><?php if($u['role']==='admin'):?><a href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a><a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a><a href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a><?php endif;?></aside>
<main class="main finance-main">
<?php if(in_array($action,['add','edit'],true)):?>
<div class="page-title"><div><h1><?=$action==='edit'?'Edit Finance Record':'Add Finance'?></h1><p class="muted">Use separate records when coverages have different finance companies.</p></div><a class="btn light" href="<?=e(appUrl($base))?>">← Back to Finance List</a></div><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<section class="panel finance-form-panel"><form method="post" action="<?=e(appUrl($base))?>" class="finance-form"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e((string)($formId??''))?>">
<section class="finance-section"><div class="finance-section-heading"><h2>Client & Policy</h2><p>Choose an insured customer. Linking a saved policy is optional.</p></div><div class="finance-grid">
<label>Insured Customer *<select id="finance-insured" name="insured_id" required><option value="">Select insured customer</option><?php foreach($insureds as $i):?><option value="<?=(int)$i['id']?>" <?=$sel('insured_id',(string)$i['id'])?>><?=e((string)$i['legal_name'])?> · DOT <?=e((string)$i['dot_number'])?></option><?php endforeach;?></select></label>
<label>Linked Policy (optional)<select id="finance-policy" name="policy_id"><option value="">No policy selected</option><?php foreach($policies as $p):?><option value="<?=(int)$p['id']?>" data-insured="<?=(int)$p['insured_id']?>" <?=$sel('policy_id',(string)$p['id'])?>><?=e((string)$p['policy_number'])?><?=trim((string)($p['business_line']??''))!==''?' · '.e((string)$p['business_line']):''?></option><?php endforeach;?></select><small>Only policies belonging to the selected customer can be linked.</small></label>
</div></section>
<section class="finance-section"><div class="finance-section-heading"><h2>Finance Plan</h2><p>Dropdown options follow the 04 Finance Billing worksheet.</p></div><div class="finance-grid">
<?php foreach($fields as [$f,$label,$type,$options,$required,$step]):?><label><?=e($label)?><?=$required?' *':''?><?php if($type==='select'):?><select name="<?=e($f)?>" <?=$required?'required':''?>><option value=""><?=$f==='status'?'Select status':($f==='coverage_policy_type'?'Select coverage':($f==='finance_company'?'Select finance company':'Select'))?></option><?php foreach($options as $o):?><option value="<?=e($o)?>" <?=$sel($f,$o)?>><?=e($o)?></option><?php endforeach;?></select><?php else:?><input type="<?=e($type)?>" name="<?=e($f)?>" value="<?=e((string)($form[$f]??''))?>" <?=$required?'required':''?> <?=$type==='number'?'min="0" step="'.($step!==''?$step:'0.01').'"':''?> <?=$f==='installments'?'max="1000"':''?>><?php endif;?></label><?php endforeach;?>
<label id="finance-company-other-wrap" class="finance-conditional">Other Finance Company Name<input type="text" name="finance_company_other" maxlength="255" value="<?=e((string)($form['finance_company_other']??''))?>" placeholder="Enter company name if known"></label>
<label class="finance-wide">Billing Notes<textarea name="billing_notes" rows="3" maxlength="5000" placeholder="Notes, reminders or billing arrangements"><?=e((string)($form['billing_notes']??''))?></textarea></label>
</div></section>
<section class="finance-section"><div class="finance-section-heading"><h2>Payment Tracking</h2><p>Optional details of payment collection.</p></div><div class="finance-grid">
<label>Autopay<select name="autopay"><option value="">Select</option><?php foreach($autopayOptions as $o):?><option value="<?=e($o)?>" <?=$sel('autopay',$o)?>><?=e($o)?></option><?php endforeach;?></select></label>
<label>Payment Method<select name="payment_method"><option value="">Select</option><?php foreach($paymentMethods as $o):?><option value="<?=e($o)?>" <?=$sel('payment_method',$o)?>><?=e($o)?></option><?php endforeach;?></select></label>
<label>Last Payment Date<input type="date" name="last_payment_date" value="<?=e((string)($form['last_payment_date']??''))?>"></label>
<label>Last Payment Amount ($)<input type="number" min="0" step="0.01" name="last_payment_amount" value="<?=e((string)($form['last_payment_amount']??''))?>"></label>
</div></section>
<div class="form-actions"><button class="btn primary" type="submit">Save Finance Record</button><a class="btn light" href="<?=e(appUrl($base))?>">Cancel</a></div></form></section>
<script>
(function(){const insured=document.getElementById('finance-insured'),policy=document.getElementById('finance-policy'),company=document.getElementById('finance-company'),other=document.getElementById('finance-company-other-wrap');if(insured&&policy){function filter(){const id=insured.value;Array.from(policy.options).forEach(o=>{if(!o.value)return;o.disabled=o.dataset.insured!==id;if(o.disabled&&o.selected)policy.value='';});}insured.addEventListener('change',filter);filter();}if(company&&other){function showOther(){other.style.display=company.value==='Other / Direct Bill / New Finance Co.'?'flex':'none';}company.addEventListener('change',showOther);showOther();}})();
</script>
<?php else:?>
<div class="page-title"><div><h1>Finance Billing Manage</h1><p class="muted">Manage premium finance billing records for clients and policies.</p></div><a class="btn primary" href="<?=e(appUrl($base.'?action=add'))?>">+ Add Finance</a></div>
<?php if($flash):?><div class="alert success"><?=e((string)$flash['message'])?></div><?php endif;?>
<?php
$qText=trim((string)($_GET['q']??''));$statusFilter=trim((string)($_GET['status']??''));$where=[];$params=[];
if($qText!==''){$where[]='(i.legal_name LIKE :q1 OR i.dot_number LIKE :q2 OR p.policy_number LIKE :q3 OR f.finance_account_number LIKE :q4)';$like='%'.$qText.'%';$params=['q1'=>$like,'q2'=>$like,'q3'=>$like,'q4'=>$like];}
if($statusFilter!==''&&in_array($statusFilter,$statuses,true)){$where[]='f.status=:status_filter';$params['status_filter']=$statusFilter;}
$sql='SELECT f.*,i.legal_name,i.dot_number,p.policy_number FROM finance_billing f JOIN insureds i ON i.id=f.insured_id LEFT JOIN policies p ON p.id=f.policy_id';if($where)$sql.=' WHERE '.implode(' AND ',$where);$sql.=' ORDER BY f.updated_at DESC,f.id DESC LIMIT 500';$stmt=db()->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
$stats=db()->query("SELECT COUNT(*) total,COALESCE(SUM(status='Current'),0) current_count,COALESCE(SUM(status='Past Due'),0) past_due_count,COALESCE(SUM(status='Cancellation Pending'),0) cancel_count FROM finance_billing")->fetch();
?>
<div class="finance-stats"><div class="finance-stat"><span>Total Records</span><strong><?=number_format((int)$stats['total'])?></strong></div><div class="finance-stat"><span>Current</span><strong><?=number_format((int)$stats['current_count'])?></strong></div><div class="finance-stat"><span>Past Due</span><strong><?=number_format((int)$stats['past_due_count'])?></strong></div><div class="finance-stat"><span>Cancellation Pending</span><strong><?=number_format((int)$stats['cancel_count'])?></strong></div></div>
<section class="panel finance-list-panel"><form method="get" class="finance-filter"><label>Search<input type="search" name="q" value="<?=e($qText)?>" placeholder="Client, DOT, policy or finance account"></label><label>Status<select name="status"><option value="">All statuses</option><?php foreach($statuses as $o):?><option value="<?=e($o)?>" <?=$statusFilter===$o?'selected':''?>><?=e($o)?></option><?php endforeach;?></select></label><div class="finance-filter-actions"><button class="btn light">Search</button><a class="btn light" href="<?=e(appUrl($base))?>">Reset</a></div></form>
<div class="table-wrap finance-table-wrap"><table class="finance-table"><thead><tr><th>Client / DOT</th><th>Policy / Coverage</th><th>Status</th><th>Finance Company</th><th>Account #</th><th>Installments / Monthly</th><th>Next Due</th><th>Pending / Past Due</th><th>Autopay / Method</th><th>Last Payment</th><th>Actions</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><strong><?=e((string)$r['legal_name'])?></strong><small>DOT <?=e((string)$r['dot_number'])?></small></td><td><?=e((string)($r['policy_number']??'—'))?><small><?=e((string)$r['coverage_policy_type'])?></small></td><td><span class="finance-status status-<?=e(strtolower(str_replace([' ','/'],'-',(string)$r['status'])))?>"><?=e((string)$r['status'])?></span></td><td><?=e($companyLabel($r))?></td><td><?=e((string)($r['finance_account_number']?:'—'))?></td><td><?=e($r['installments']===null?'—':(string)$r['installments'])?><small><?=$money($r['monthly_amount'])?> / month</small></td><td><?=$date($r['next_due_date'])?></td><td><?=$money($r['pending_past_due'])?></td><td><?=e((string)($r['autopay']?:'—'))?><small><?=e((string)($r['payment_method']?:'—'))?></small></td><td><?=$date($r['last_payment_date'])?><small><?=$money($r['last_payment_amount'])?></small></td><td><div class="finance-row-actions"><a class="btn light small-btn" href="<?=e(appUrl($base.'?action=edit&id='.(int)$r['id']))?>">Edit</a><form method="post" onsubmit="return confirm('Delete this Finance Billing record?')"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn light small-btn finance-delete">Delete</button></form></div></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="11" class="muted finance-empty"><?=($qText!==''||$statusFilter!=='')?'No records match your filters.':'No Finance Billing records yet. Click “Add Finance” to create the first entry.'?></td></tr><?php endif;?>
</tbody></table></div><div class="finance-list-footer"><span class="muted small">Showing <?=count($rows)?> records (maximum 500 per view).</span><span class="muted small">Use separate rows when coverages have different finance companies.</span></div></section>
<?php endif;?>
</main></div></body></html>
