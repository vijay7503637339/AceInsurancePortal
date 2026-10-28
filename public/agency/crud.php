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
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($entity['label'])?></title><link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>"></head><body><header class="topbar"><div><strong>ACE Insurance</strong><span>Agency Portal</span></div><div><?=e($u['full_name'])?> · <?=e($u['role'])?> <a class="btn light small-btn" href="<?=e(appUrl('/agency/change-password.php'))?>">Change Password</a><a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a></div></header><div class="layout"><aside class="sidebar"><a href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a><a href="<?=e(appUrl('/agency/finance.php'))?>">Finance Billing Manage</a><a href="<?=e(appUrl('/agency/claims.php'))?>">Claims Management</a><?php foreach(Crud::ENTITIES as $k=>$v):?><a class="<?=$k===$key?'selected':''?>" href="<?=e(appUrl('/agency/crud.php?entity='.$k))?>"><?=e($v['label'])?></a><?php endforeach;?><?php if($u['role']==='admin'):?><a href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a><a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a><a href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a><?php endif;?></aside><main class="main"><?php if($action==='list'):?>
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
<?php if($key==='policies'):
 $policyCoverage=[];
 $rawCoverage=$editing['coverage_data']??null;
 if(is_string($rawCoverage)&&$rawCoverage!==''){$decodedCoverage=json_decode($rawCoverage,true);if(is_array($decodedCoverage))$policyCoverage=$decodedCoverage;}
 $insuranceTypeKeys=['commercial_general_liability','automobile_liability','physical_damage','motor_truck_cargo','umbrella_liability','excess_liability','workers_compensation_employers_liability'];
 $insuranceTypeLabels=[
  'commercial_general_liability'=>'Commercial General Liability',
  'automobile_liability'=>'Automobile Liability',
  'physical_damage'=>'Physical Damage',
  'motor_truck_cargo'=>'Motor Truck Cargo',
  'umbrella_liability'=>'Umbrella Liability',
  'excess_liability'=>'Excess Liability',
  'workers_compensation_employers_liability'=>"Workers Compensation & Employers' Liability"
 ];
 $selectedInsuranceTypes=array_key_exists('insurance_types_present',$editing)
  ? (is_array($editing['insurance_types']??null)?array_map('strval',$editing['insurance_types']):[])
  : (is_array($policyCoverage['insurance_types']??null)?$policyCoverage['insurance_types']:[]);
 $selectedCoverageOptions=array_key_exists('coverage_options_present',$editing)
  ? (is_array($editing['coverage_options']??null)?$editing['coverage_options'] : [])
  : (is_array($policyCoverage['coverage_options']??null)?$policyCoverage['coverage_options']:[]);
 $selectedInsuranceCarriers=array_key_exists('insurance_types_present',$editing)
  ? (is_array($editing['insurance_carriers']??null)?$editing['insurance_carriers']:[])
  : (is_array($policyCoverage['insurance_carriers']??null)?$policyCoverage['insurance_carriers']:[]);
 $combinedSingleLimit=array_key_exists('combined_single_limit_present',$editing)?(string)($editing['combined_single_limit']??''):(string)($policyCoverage['combined_single_limit']??'');

 $cglData=is_array($policyCoverage['commercial_general_liability']??null)?$policyCoverage['commercial_general_liability']:[];
 $cglLimits=is_array($cglData['limits']??null)?$cglData['limits']:[];
 $cglPolicyNumber=(string)($editing['cgl_policy_number']??$cglData['policy_number']??'');
 $cglEffectiveDate=(string)($editing['cgl_effective_date']??$cglData['effective_date']??'');
 $cglExpirationDate=(string)($editing['cgl_expiration_date']??$cglData['expiration_date']??'');
 $cglLimitFields=[
  'each_occurrence'=>'Each Occurrence',
  'damage_to_rented_premises'=>'Damage to Rented Premises',
  'medical_expense'=>'Medical Expense (Any One Person)',
  'personal_advertising_injury'=>'Personal & Advertising Injury',
  'general_aggregate'=>'General Aggregate',
  'products_completed_operations_aggregate'=>'Products - Completed Operations Aggregate'
 ];
 foreach($cglLimitFields as $limitKey=>$limitLabel) {
  $cglLimits[$limitKey]=(string)($editing['cgl_limit_'.$limitKey]??$cglLimits[$limitKey]??'');
 }

 $physicalDamageData=is_array($policyCoverage['physical_damage']??null)?$policyCoverage['physical_damage']:[];
 $physicalDamagePolicyNumber=(string)($editing['physical_damage_policy_number']??$physicalDamageData['policy_number']??'');
 $physicalDamageEffectiveDate=(string)($editing['physical_damage_effective_date']??$physicalDamageData['effective_date']??'');
 $physicalDamageExpirationDate=(string)($editing['physical_damage_expiration_date']??$physicalDamageData['expiration_date']??'');
 $physicalDamageAmount=(string)($editing['physical_damage_amount']??$physicalDamageData['amount']??'');
 $physicalDamageDescription=(string)($editing['physical_damage_description']??$physicalDamageData['description']??'');

 $motorTruckCargoData=is_array($policyCoverage['motor_truck_cargo']??null)?$policyCoverage['motor_truck_cargo']:[];
 $motorTruckCargoPolicyNumber=(string)($editing['motor_truck_cargo_policy_number']??$motorTruckCargoData['policy_number']??'');
 $motorTruckCargoEffectiveDate=(string)($editing['motor_truck_cargo_effective_date']??$motorTruckCargoData['effective_date']??'');
 $motorTruckCargoExpirationDate=(string)($editing['motor_truck_cargo_expiration_date']??$motorTruckCargoData['expiration_date']??'');
 $motorTruckCargoAmount=(string)($editing['motor_truck_cargo_amount']??$motorTruckCargoData['amount']??'');
 $motorTruckCargoDescription=(string)($editing['motor_truck_cargo_description']??$motorTruckCargoData['description']??'');

 $aggregateOtherDescription=array_key_exists('aggregate_other_description_present',$editing)?(string)($editing['aggregate_other_description']??''):(string)($policyCoverage['aggregate_other_description']??'');
 $workersCompOtherText=array_key_exists('workers_compensation_other_text_present',$editing)?(string)($editing['workers_compensation_other_text']??''):(string)($policyCoverage['workers_compensation_other_text']??'');
 $deductibleRetentionAmount=array_key_exists('deductible_retention_amount_present',$editing)?(string)($editing['deductible_retention_amount']??''):(string)($policyCoverage['deductible_retention_amount']??'');
 $workersCompExcluded=array_key_exists('workers_compensation_excluded_present',$editing)?(string)($editing['workers_compensation_excluded']??''):(string)($policyCoverage['workers_compensation_excluded']??'');
 $optionLabels=[
  'commercial_general_liability'=>['claims_made'=>'Claims-Made','occur'=>'Occur','aggregate_policy'=>'Policy','aggregate_project'=>'Project','aggregate_location'=>'Location'],
  'automobile_liability'=>['any_auto'=>'Any Auto','all_owned_autos'=>'All Owned Autos','hired_autos'=>'Hired Autos','scheduled_autos'=>'Scheduled Autos','non_owned_autos'=>'Non-Owned Autos'],
  'physical_damage'=>[],
  'motor_truck_cargo'=>[],
  'umbrella_liability'=>['occur'=>'Occur','claims_made'=>'Claims-Made','deductible'=>'Deductible','retention'=>'Retention'],
  'excess_liability'=>['occur'=>'Occur','claims_made'=>'Claims-Made','deductible'=>'Deductible','retention'=>'Retention'],
  'workers_compensation_employers_liability'=>['per_statute'=>'Per Statute','other'=>'Other']
 ];
?>
<div class="acord-coverage-manager">
 <div class="acord-coverage-heading"><div><h3>ACORD 25 — Type of Insurance</h3><p class="muted">Select the insurance types that apply. Policy details and limits below are stored with this policy and used on the certificate.</p></div><span class="count-pill" id="coverage-type-count"><?=count(array_intersect($selectedInsuranceTypes,['commercial_general_liability','automobile_liability','physical_damage','motor_truck_cargo']))?> / 4 selected</span></div>
 <input type="hidden" name="insurance_types_present" value="1">
 <input type="hidden" name="coverage_options_present" value="1">
 <input type="hidden" name="combined_single_limit_present" value="1">
 <input type="hidden" name="cgl_data_present" value="1">
 <input type="hidden" name="physical_damage_data_present" value="1">
 <input type="hidden" name="motor_truck_cargo_data_present" value="1">
 <?php foreach($insuranceTypeKeys as $preservedType): if(in_array($preservedType,['commercial_general_liability','automobile_liability','physical_damage','motor_truck_cargo'],true)||!in_array($preservedType,$selectedInsuranceTypes,true))continue;
  $preservedCarrier=(string)($selectedInsuranceCarriers[$preservedType]??'A');
  if(!in_array($preservedCarrier,['A','B','C','D','E','F'],true))$preservedCarrier='A';
 ?>
  <input type="hidden" name="insurance_types[]" value="<?=e($preservedType)?>">
  <input type="hidden" name="insurance_carriers[<?=e($preservedType)?>]" value="<?=e($preservedCarrier)?>">
  <?php foreach((array)($selectedCoverageOptions[$preservedType]??[]) as $preservedOption): ?>
   <input type="hidden" name="coverage_options[<?=e($preservedType)?>][]" value="<?=e((string)$preservedOption)?>">
  <?php endforeach; ?>
 <?php endforeach; ?>
 <div class="acord-coverage-cards acord-coverage-cards-policy">
 <?php foreach($insuranceTypeLabels as $typeKey=>$typeLabel):
  // Preserve legacy future sections in code, but expose only the four types requested by the client.
  if(!in_array($typeKey,['commercial_general_liability','automobile_liability','physical_damage','motor_truck_cargo'],true))continue;
  $typeSelected=in_array($typeKey,$selectedInsuranceTypes,true);
  $typeCarrier=(string)($selectedInsuranceCarriers[$typeKey]??'A');
  if(!in_array($typeCarrier,['A','B','C','D','E','F'],true))$typeCarrier='A';
  $groupOptions=is_array($selectedCoverageOptions[$typeKey]??null)?array_map('strval',$selectedCoverageOptions[$typeKey]):[];
 ?>
  <section class="acord-coverage-card <?=$typeSelected?'is-selected':''?>" data-coverage-card="<?=e($typeKey)?>">
   <div class="acord-coverage-card-head">
    <label class="acord-type-check"><input type="checkbox" name="insurance_types[]" value="<?=e($typeKey)?>" <?=$typeSelected?'checked':''?>><span><?=e($typeLabel)?></span></label>
    <label class="acord-insurer-letter">Insurer Letter
     <select name="insurance_carriers[<?=e($typeKey)?>]" data-insurer-select <?=$typeSelected?'':'disabled'?>>
      <?php foreach(range('A','F') as $letter):?><option value="<?=$letter?>" <?=$typeCarrier===$letter?'selected':''?>><?=$letter?></option><?php endforeach;?>
     </select>
    </label>
   </div>
   <div class="acord-coverage-options">
    <?php foreach($optionLabels[$typeKey] as $optionKey=>$optionLabel):?>
     <label class="acord-option"><input type="checkbox" name="coverage_options[<?=e($typeKey)?>][]" value="<?=e($optionKey)?>" data-coverage-option="<?=e($optionKey)?>" <?=in_array($optionKey,$groupOptions,true)?'checked':''?>><span><?=e($optionLabel)?></span></label>
    <?php endforeach;?>
   </div>
   <?php if($typeKey==='commercial_general_liability'):?>
    <div class="acord-fields-grid acord-policy-fields">
     <label>Policy Number
      <input data-coverage-detail type="text" name="cgl_policy_number" value="<?=e($cglPolicyNumber)?>" maxlength="100" placeholder="Commercial General Liability policy number">
     </label>
     <label>Policy Start Date
      <input data-coverage-detail type="date" name="cgl_effective_date" value="<?=e($cglEffectiveDate)?>">
     </label>
     <label>Policy End Date
      <input data-coverage-detail type="date" name="cgl_expiration_date" value="<?=e($cglExpirationDate)?>">
     </label>
    </div>
    <div class="acord-limit-panel">
     <strong>Limits</strong>
     <div class="acord-limits-grid">
      <?php foreach($cglLimitFields as $limitKey=>$limitLabel): ?>
       <label><?=e($limitLabel)?>
        <span class="acord-money-input"><span>$</span><input data-coverage-detail type="text" inputmode="decimal" name="cgl_limit_<?=e($limitKey)?>" value="<?=e((string)($cglLimits[$limitKey]??''))?>" placeholder="0.00" maxlength="20"></span>
       </label>
      <?php endforeach; ?>
     </div>
    </div>
   <?php elseif($typeKey==='automobile_liability'):?>
    <label class="acord-limit-field">Combined Single Limit (Each Accident)
     <span class="acord-money-input"><span>$</span><input type="text" inputmode="decimal" name="combined_single_limit" value="<?=e($combinedSingleLimit)?>" placeholder="e.g. 1000000" maxlength="20" aria-describedby="combined-limit-help"></span>
     <small id="combined-limit-help">Enter the Auto Liability limit. Commas and a leading $ are accepted.</small>
    </label>
   <?php elseif($typeKey==='physical_damage'):?>
    <div class="acord-fields-grid acord-policy-fields">
     <label>Policy Number
      <input data-coverage-detail type="text" name="physical_damage_policy_number" value="<?=e($physicalDamagePolicyNumber)?>" maxlength="100" placeholder="Physical Damage policy number">
     </label>
     <label>Policy Start Date
      <input data-coverage-detail type="date" name="physical_damage_effective_date" value="<?=e($physicalDamageEffectiveDate)?>">
     </label>
     <label>Policy End Date
      <input data-coverage-detail type="date" name="physical_damage_expiration_date" value="<?=e($physicalDamageExpirationDate)?>">
     </label>
    </div>
    <div class="acord-fields-grid acord-custom-insurance-extra">
     <label>Amount / Limit
      <span class="acord-money-input"><span>$</span><input data-coverage-detail type="text" inputmode="decimal" name="physical_damage_amount" value="<?=e($physicalDamageAmount)?>" maxlength="20" placeholder="0.00"></span>
     </label>
     <label class="acord-description-input">Description
      <input data-coverage-detail type="text" name="physical_damage_description" value="<?=e($physicalDamageDescription)?>" maxlength="250" placeholder="Physical damage coverage description">
     </label>
    </div>
   <?php elseif($typeKey==='motor_truck_cargo'):?>
    <div class="acord-fields-grid acord-policy-fields">
     <label>Policy Number
      <input data-coverage-detail type="text" name="motor_truck_cargo_policy_number" value="<?=e($motorTruckCargoPolicyNumber)?>" maxlength="100" placeholder="Motor Truck Cargo policy number">
     </label>
     <label>Policy Start Date
      <input data-coverage-detail type="date" name="motor_truck_cargo_effective_date" value="<?=e($motorTruckCargoEffectiveDate)?>">
     </label>
     <label>Policy End Date
      <input data-coverage-detail type="date" name="motor_truck_cargo_expiration_date" value="<?=e($motorTruckCargoExpirationDate)?>">
     </label>
    </div>
    <div class="acord-fields-grid acord-custom-insurance-extra">
     <label>Amount / Limit
      <span class="acord-money-input"><span>$</span><input data-coverage-detail type="text" inputmode="decimal" name="motor_truck_cargo_amount" value="<?=e($motorTruckCargoAmount)?>" maxlength="20" placeholder="0.00"></span>
     </label>
     <label class="acord-description-input">Description
      <input data-coverage-detail type="text" name="motor_truck_cargo_description" value="<?=e($motorTruckCargoDescription)?>" maxlength="250" placeholder="Motor truck cargo coverage description">
     </label>
    </div>
   <?php elseif($typeKey==='umbrella_liability'):?>
    <label class="acord-extra-field">Shared Deductible / Retention Amount (Umbrella / Excess)
     <input type="text" name="deductible_retention_amount" value="<?=e($deductibleRetentionAmount)?>" maxlength="30" placeholder="Amount, if applicable">
    </label>
   <?php elseif($typeKey==='excess_liability'):?>
    <p class="muted acord-shared-limit-note">Deductible / retention amount is entered once in the Umbrella Liability card above.</p>
   <?php elseif($typeKey==='workers_compensation_employers_liability'):?>
    <label class="acord-extra-field">Other description
     <input type="text" name="workers_compensation_other_text" value="<?=e($workersCompOtherText)?>" maxlength="180" placeholder="Describe other workers compensation coverage">
    </label>
    <div class="acord-excluded-options"><span>Proprietor / Partner / Executive Officer Excluded?</span>
     <?php foreach(['yes'=>'Yes','no'=>'No','na'=>'N/A'] as $excludedKey=>$excludedLabel):?>
      <label class="acord-option"><input type="radio" name="workers_compensation_excluded" value="<?=e($excludedKey)?>" <?=$workersCompExcluded===$excludedKey?'checked':''?>><span><?=e($excludedLabel)?></span></label>
     <?php endforeach;?>
    </div>
   <?php endif;?>
  </section>
 <?php endforeach;?>
 </div>
 <p class="muted acord-coverage-note">The insurer letter maps the selected coverage to Insurer A–F above. Only checked coverage types are marked in the ACORD PDF.</p>
</div>
<script>
(function(){
 const root=document.querySelector('.acord-coverage-manager');
 if(!root)return;
 const count=root.querySelector('#coverage-type-count');
 function refreshCard(card){
  const typeCheck=card.querySelector('.acord-type-check input');
  const selected=!!typeCheck.checked;
  card.classList.toggle('is-selected',selected);
  card.querySelectorAll('[data-insurer-select]').forEach(input=>{input.disabled=!selected;});
  card.querySelectorAll('[data-coverage-option]').forEach(input=>{input.disabled=!selected;});
  card.querySelectorAll('[data-coverage-detail]').forEach(input=>{input.disabled=!selected;});
  const aggregateText=card.querySelector('input[name="aggregate_other_description"]');
  if(aggregateText)aggregateText.disabled=!selected;
  const limitInput=card.querySelector('input[name="combined_single_limit"]');
  if(limitInput)limitInput.disabled=!selected;
  const otherWc=card.querySelector('input[data-coverage-option="other"]');
  const wcText=card.querySelector('input[name="workers_compensation_other_text"]');
  if(wcText)wcText.disabled=!selected||!otherWc.checked;
  card.querySelectorAll('input[name="workers_compensation_excluded"]').forEach(input=>{input.disabled=!selected;});
 }
 function refresh(){
  const cards=Array.from(root.querySelectorAll('[data-coverage-card]'));
  cards.forEach(refreshCard);
  const umbrellaCard=root.querySelector('[data-coverage-card="umbrella_liability"]');
  const excessCard=root.querySelector('[data-coverage-card="excess_liability"]');
  const umbrellaSelected=!!umbrellaCard?.querySelector('.acord-type-check input')?.checked;
  const excessSelected=!!excessCard?.querySelector('.acord-type-check input')?.checked;
  const deductibleOrRetention=!!root.querySelector('input[data-coverage-option="deductible"]:checked, input[data-coverage-option="retention"]:checked');
  const sharedAmount=root.querySelector('input[name="deductible_retention_amount"]');
  if(sharedAmount)sharedAmount.disabled=!(umbrellaSelected||excessSelected)||!deductibleOrRetention;
  if(count)count.textContent=cards.filter(card=>card.querySelector('.acord-type-check input').checked).length+' / 4 selected';
 }
 root.querySelectorAll('.acord-type-check input,.acord-coverage-options input,.acord-excluded-options input,[data-coverage-detail]').forEach(input=>input.addEventListener('change',refresh));
 refresh();
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
<div class="crud-list-summary"><strong><?=count($rows)?></strong><span>matching record<?=count($rows)===1?'':'s'?></span><span class="muted">· Searches latest 500 records</span></div><div class="table-wrap"><table><thead><tr><th>ID</th><?php foreach($entity['fields'] as $f=>$m):?><th><?=e($m[0])?></th><?php endforeach;?><th>Actions</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=$r['id']?></td><?php foreach($entity['fields'] as $f=>$m):?><td><?=e(is_scalar($r[$f]??null)?(string)($r[$f]??''):'')?></td><?php endforeach;?><td class="actions"><a href="<?=e(appUrl('/agency/crud.php?entity='.$key.'&action=edit&id='.(int)$r['id']))?>">Edit</a><form method="post" onsubmit="return confirm('Delete this record?')"><input type="hidden" name="_csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="entity" value="<?=e($key)?>"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="action" value="delete"><button class="link danger">Delete</button></form></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="<?=count($entity['fields'])+2?>" class="muted">No records yet.</td></tr><?php endif;?></tbody></table></div></div><?php endif;?></main></div></body></html>