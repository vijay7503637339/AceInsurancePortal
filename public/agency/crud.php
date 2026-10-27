<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;use AcePortal\Crud;
$u=Auth::requireRoles(['admin','staff']);
$key=(string)($_GET['entity']??$_POST['entity']??'insureds');
$entity=Crud::entity($key);
$action=(string)($_GET['action']??'list');
if(!in_array($action,['list','add','edit'],true))$action='list';
$idRaw=trim((string)($_GET['id']??($_POST['id']??'')));
$id=null;
if($action==='edit'){
 if($idRaw===''||!ctype_digit($idRaw)||(int)$idRaw<1){http_response_code(400);exit('Invalid record ID.');}
 $id=(int)$idRaw;
}
$error=null;$editing=[];
if($action==='edit'&&$_SERVER['REQUEST_METHOD']!=='POST'){
 $editing=Crud::find($key,(int)$id);
 if(!$editing){http_response_code(404);exit('Record not found.');}
}elseif(in_array($action,['add','edit'],true)&&$_SERVER['REQUEST_METHOD']==='POST'){$editing=$_POST;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$postAction=(string)($_POST['action']??'save');
 try{
  if($postAction==='delete'){
   $deleteRaw=trim((string)($_POST['id']??''));$deleteId=$deleteRaw!==''?filter_var($deleteRaw,FILTER_VALIDATE_INT):false;
   if(!$deleteId||$deleteId<1)throw new \InvalidArgumentException('Invalid record ID.');
   Crud::delete($key,(int)$deleteId);flash('success','Record deleted.');redirect('/agency/crud.php?entity='.urlencode($key));
  }
  if(!in_array($action,['add','edit'],true))throw new \InvalidArgumentException('Open Add or Edit before saving a record.');
  $saveRaw=trim((string)($_POST['id']??''));$saveId=$saveRaw!==''?filter_var($saveRaw,FILTER_VALIDATE_INT):null;
  if($saveRaw!==''&&(!$saveId||$saveId<1))throw new \InvalidArgumentException('Invalid record ID.');
  if($action==='edit'&&!$saveId)throw new \InvalidArgumentException('Record ID is missing. Return to the list and reopen this record.');
  Crud::save($key,$_POST,$saveId?(int)$saveId:null,(int)$u['id']);
  flash('success',$action==='edit'?'Record updated.':'Record added.');redirect('/agency/crud.php?entity='.urlencode($key));
 }catch(\Throwable $x){
  error_log('Agency CRUD save failed for '.$key.': '.$x->getMessage());
  $error=$x instanceof \PDOException&&(string)$x->getCode()==='23000'?'Duplicate value or linked record prevents this action.':$x->getMessage();
  if($postAction==='delete'){$action='list';$id=null;}else{
   $saveRaw=trim((string)($_POST['id']??''));$saveId=$saveRaw!==''?filter_var($saveRaw,FILTER_VALIDATE_INT):null;
   $action=$saveId?'edit':'add';$id=$saveId?(int)$saveId:null;$editing=$_POST;
  }
 }
}
$flash=getFlash();
$allRows=Crud::rows($key);
$customers=db()->query('SELECT id,legal_name,dot_number FROM insureds ORDER BY legal_name')->fetchAll();
$customerMap=[];foreach($customers as $c)$customerMap[(int)$c['id']]=$c;
$search=trim((string)($_GET['q']??''));$statusFilter=trim((string)($_GET['status']??''));
$statusOptions=[];
if(isset($entity['fields']['status'])&&str_starts_with((string)$entity['fields']['status'][1],'select:'))$statusOptions=explode(',',substr((string)$entity['fields']['status'][1],7));
$rows=array_values(array_filter($allRows,static function(array $r)use($search,$statusFilter,$statusOptions,$customerMap):bool{
 if($statusFilter!==''&&$statusOptions&&(string)($r['status']??'')!==$statusFilter)return false;
 if($search==='')return true;
 $parts=[];foreach($r as $v)if(is_scalar($v))$parts[]=(string)$v;
 if(isset($r['insured_id'],$customerMap[(int)$r['insured_id']])){$parts[]=(string)$customerMap[(int)$r['insured_id']]['legal_name'];$parts[]=(string)$customerMap[(int)$r['insured_id']]['dot_number'];}
 return stripos(implode(' ',$parts),$search)!==false;
}));
$listUrl='/agency/crud.php?entity='.urlencode($key);
$formUrl=$listUrl.'&action='.$action.($id!==null?'&id='.(int)$id:'');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($entity['label'])?></title><link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>"></head><body><header class="topbar"><div><strong>ACE Insurance</strong><span>Agency Portal</span></div><div><?=e($u['full_name'])?> · <?=e($u['role'])?> <a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a></div></header><div class="layout"><aside class="sidebar"><a href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a><a href="<?=e(appUrl('/agency/finance.php'))?>">Finance Billing Manage</a><?php foreach(Crud::ENTITIES as $k=>$v):?><a class="<?=$k===$key?'selected':''?>" href="<?=e(appUrl('/agency/crud.php?entity='.$k))?>"><?=e($v['label'])?></a><?php endforeach;?><?php if($u['role']==='admin'):?><a href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a><a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a><a href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a><?php endif;?></aside><main class="main"><?php if($action==='list'):?>
<div class="page-title"><div><h1><?=e($entity['label'])?></h1><p class="muted">Browse, search and manage existing records.</p></div><a class="btn primary" href="<?=e(appUrl($listUrl.'&action=add'))?>">+ Add <?=e($entity['label'])?></a></div>
<?php else:?>
<div class="page-title"><div><h1><?=$action==='edit'?'Edit Record #'.$id:'Add New Record'?></h1><p class="muted"><?=$action==='edit'?'Update the selected record and save your changes.':'Enter the details below to create a new record.'?></p></div><a class="btn light" href="<?=e(appUrl($listUrl))?>">← Back to List</a></div>
<?php endif;?>
<?php if($flash):?><div class="alert success"><?=e($flash['message'])?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<?php if($action==='add'||$action==='edit'):?><div class="panel crud-form-panel"><h2><?=$action==='edit'?'Edit Record #'.$id:'Add New Record'?></h2><form method="post" action="<?=e(appUrl($formUrl))?>" class="form-grid crud-form"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="entity" value="<?=e($key)?>"><input type="hidden" name="id" value="<?=e((string)($id??''))?>"><?php foreach($entity['fields'] as $f=>$m):if($key==='policies'&&preg_match('/^insurer_[a-f]_(name|naic)$/',$f))continue;$v=$editing[$f]??'';?><label><?=e($m[0])?><?=$m[2]?' *':''?><?php if($m[1]==='insured_select'):?><select name="<?=e($f)?>" <?=$m[2]?'required':''?>><option value="">Select insured customer</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>" <?=(string)$v===(string)$c['id']?'selected':''?>><?=e($c['legal_name'].' (DOT '.$c['dot_number'].')')?></option><?php endforeach;?></select><?php elseif(str_starts_with($m[1],'select:')):?><select name="<?=e($f)?>" <?=$m[2]?'required':''?>><option value="">Select</option><?php foreach(explode(',',substr($m[1],7)) as $o):?><option value="<?=e($o)?>" <?=(string)$v===$o?'selected':''?>><?=e(ucfirst($o))?></option><?php endforeach;?></select><?php else:?><input type="<?=e($m[1])?>" name="<?=e($f)?>" value="<?=e(is_scalar($v)?(string)$v:'')?>" <?=$m[2]?'required':''?>><?php endif;?></label><?php endforeach;?><?php if($key==='policies'): ?>
<div class="insurer-manager">
 <div class="insurer-manager-heading"><div><h3>Insurance Companies</h3><p class="muted">Insurer A is the primary company. Add B–F only when a policy has additional insurers.</p></div><span class="count-pill" id="insurer-count">1 / 6</span></div>
 <div class="insurer-manager-rows" id="insurer-rows">
 <?php foreach(range('A','F') as $letter):$s=strtolower($letter);$companyName=(string)($editing['insurer_'.$s.'_name']??'');$naicNumber=(string)($editing['insurer_'.$s.'_naic']??'');if($letter!=='A'&&trim($companyName)===''&&trim($naicNumber)==='')continue; ?>
 <section class="insurer-entry" data-insurer-letter="<?=e($letter)?>">
  <div class="insurer-entry-title"><strong>Insurer <?=e($letter)?><?=$letter==='A'?' — Primary':''?></strong><?php if($letter!=='A'):?><button type="button" class="link danger remove-insurer">Remove</button><?php endif;?></div>
  <div class="insurer-entry-grid"><label>Company Name<input type="text" name="insurer_<?=$s?>_name" value="<?=e($companyName)?>" maxlength="180" placeholder="Insurance company name"></label><label>NAIC Number<input type="text" name="insurer_<?=$s?>_naic" value="<?=e($naicNumber)?>" maxlength="30" placeholder="NAIC number"></label></div>
 </section>
 <?php endforeach; ?>
 </div>
 <div class="insurer-manager-footer"><button type="button" class="btn light" id="add-insurer">+ Add Insurer</button><span class="muted small">Maximum 6 insurers (A–F).</span></div>
</div>
<script>
(function(){
 const container=document.getElementById('insurer-rows'), addButton=document.getElementById('add-insurer'), count=document.getElementById('insurer-count');
 if(!container||!addButton||!count)return;
 const letters=['A','B','C','D','E','F'];
 function used(){return Array.from(container.querySelectorAll('.insurer-entry')).map(row=>row.dataset.insurerLetter);}
 function refresh(){const active=used();count.textContent=active.length+' / 6';addButton.disabled=active.length>=6;addButton.textContent=active.length>=6?'All insurers added':'+ Add Insurer';}
 function field(labelText,name,placeholder){const label=document.createElement('label');label.appendChild(document.createTextNode(labelText));const input=document.createElement('input');input.type='text';input.name=name;input.maxLength=name.endsWith('_naic')?30:180;input.placeholder=placeholder;label.appendChild(input);return label;}
 function sortRows(){Array.from(container.querySelectorAll('.insurer-entry')).sort((a,b)=>letters.indexOf(a.dataset.insurerLetter)-letters.indexOf(b.dataset.insurerLetter)).forEach(row=>container.appendChild(row));}
 function addRow(letter){const suffix=letter.toLowerCase(),section=document.createElement('section');section.className='insurer-entry';section.dataset.insurerLetter=letter;
  const title=document.createElement('div');title.className='insurer-entry-title';const strong=document.createElement('strong');strong.textContent='Insurer '+letter;
  const remove=document.createElement('button');remove.type='button';remove.className='link danger remove-insurer';remove.textContent='Remove';remove.addEventListener('click',()=>{section.remove();sortRows();refresh();});
  title.append(strong,remove);const fields=document.createElement('div');fields.className='insurer-entry-grid';
  fields.append(field('Company Name','insurer_'+suffix+'_name','Insurance company name'),field('NAIC Number','insurer_'+suffix+'_naic','NAIC number'));
  section.append(title,fields);container.appendChild(section);sortRows();refresh();const input=section.querySelector('input[name="insurer_'+suffix+'_name"]');if(input)input.focus();}
 container.querySelectorAll('.remove-insurer').forEach(button=>button.addEventListener('click',()=>{const section=button.closest('.insurer-entry');if(section&&section.dataset.insurerLetter!=='A')section.remove();sortRows();refresh();}));
 addButton.addEventListener('click',()=>{const active=used();const next=letters.find(letter=>!active.includes(letter));if(next)addRow(next);});refresh();
})();
</script>
<?php endif; ?>
<div class="form-actions"><button class="btn primary">Save Record</button><a class="btn light" href="<?=e(appUrl($listUrl))?>">Cancel</a></div></form></div><?php else: ?><div class="panel crud-list-panel"><h2><?=e($entity['label'])?> List</h2>
<form method="get" action="<?=e(appUrl('/agency/crud.php'))?>" class="crud-filter">
<input type="hidden" name="entity" value="<?=e($key)?>">
<label class="crud-search-field">Search<input type="search" name="q" value="<?=e($search)?>" placeholder="<?=e('Search '.strtolower($entity['label']).'...')?>"></label>
<?php if($statusOptions):?><label>Status<select name="status"><option value="">All statuses</option><?php foreach($statusOptions as $option):?><option value="<?=e($option)?>" <?=$statusFilter===$option?'selected':''?>><?=e(ucfirst($option))?></option><?php endforeach;?></select></label><?php endif;?>
<div class="crud-filter-actions"><button class="btn light" type="submit">Search</button><a class="btn light" href="<?=e(appUrl($listUrl))?>">Reset</a></div>
</form>
<div class="crud-list-summary"><strong><?=count($rows)?></strong><span>matching record<?=count($rows)===1?'':'s'?></span><span class="muted">· Searches latest 500 records</span></div><div class="table-wrap"><table><thead><tr><th>ID</th><?php foreach($entity['fields'] as $f=>$m):?><th><?=e($m[0])?></th><?php endforeach;?><th>Actions</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=$r['id']?></td><?php foreach($entity['fields'] as $f=>$m):?><td><?=e(is_scalar($r[$f]??null)?(string)($r[$f]??''):'')?></td><?php endforeach;?><td class="actions"><a href="<?=e(appUrl('/agency/crud.php?entity='.$key.'&id='.(int)$r['id']))?>">Edit</a><form method="post" onsubmit="return confirm('Delete this record?')"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="entity" value="<?=e($key)?>"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="delete"><button class="link danger">Delete</button></form></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="<?=count($entity['fields'])+2?>" class="muted">No records yet.</td></tr><?php endif;?></tbody></table></div></div><?php endif;?></main></div></body></html>