<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;
$user=Auth::requireRoles(['admin','staff']);
$safeCount=static function(string $sql):int{try{return (int)db()->query($sql)->fetchColumn();}catch(\Throwable $e){error_log('Dashboard count query failed: '.$e->getMessage());return 0;}};
$counts=[];foreach(['insureds'=>'Insured Customers','vehicles'=>'Vehicles','policies'=>'Policies','drivers'=>'Drivers'] as $table=>$label)$counts[$label]=(int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$policyExpiringCount=0;
$expiringPolicies=[];
try {
 // Policy expiry may be stored in the main column or per-coverage data.
 $q=db()->query(
  "SELECT expiring.id,expiring.policy_number,expiring.expiration_date,
          COALESCE(NULLIF(expiring.insurer_a_name,''),NULLIF(expiring.carrier_name,''),'—') AS carrier_name,
          expiring.status,
          i.legal_name,i.dot_number,i.email,i.phone,
          DATEDIFF(expiring.expiration_date,CURDATE()) AS days_remaining
   FROM (
     SELECT p.id,p.insured_id,p.policy_number,p.status,p.insurer_a_name,p.carrier_name,
            LEAST(
              COALESCE(p.expiration_date,CAST('9999-12-31' AS DATE)),
              COALESCE(STR_TO_DATE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.coverage_data,'$.commercial_general_liability.expiration_date')),''),'%Y-%m-%d'),CAST('9999-12-31' AS DATE)),
              COALESCE(STR_TO_DATE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.coverage_data,'$.physical_damage.expiration_date')),''),'%Y-%m-%d'),CAST('9999-12-31' AS DATE)),
              COALESCE(STR_TO_DATE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p.coverage_data,'$.motor_truck_cargo.expiration_date')),''),'%Y-%m-%d'),CAST('9999-12-31' AS DATE))
            ) AS expiration_date
     FROM policies p
     WHERE LOWER(COALESCE(p.status,'')) IN ('active','draft')
   ) AS expiring
   INNER JOIN insureds i ON i.id=expiring.insured_id
   WHERE expiring.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
   ORDER BY expiring.expiration_date ASC,i.legal_name ASC"
 );
 $expiringPolicies=$q->fetchAll();
 $policyExpiringCount=count($expiringPolicies);
} catch(\Throwable $e) {
 error_log('Dashboard expiring policies query failed: '.$e->getMessage());
}
$financeTotal=$safeCount('SELECT COUNT(*) FROM finance_billing');
$financePastDue=$safeCount("SELECT COUNT(*) FROM finance_billing WHERE status='Past Due'");
$financeDueSoon=$safeCount("SELECT COUNT(*) FROM finance_billing WHERE next_due_date IS NOT NULL AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status NOT IN ('Paid Off','Cancelled')");
$financePastDueAmount=0.0;try{$financePastDueAmount=(float)(db()->query("SELECT COALESCE(SUM(pending_past_due),0) FROM finance_billing WHERE status='Past Due'")->fetchColumn()?:0);}catch(\Throwable $e){error_log('Dashboard finance amount query failed: '.$e->getMessage());}
$claimTotal=$safeCount('SELECT COUNT(*) FROM claims');
$claimActive=$safeCount("SELECT COUNT(*) FROM claims WHERE LOWER(COALESCE(status,'')) NOT IN ('closed','denied','cancelled','paid off')");
$claimClosed=$safeCount("SELECT COUNT(*) FROM claims WHERE LOWER(COALESCE(status,''))='closed'");
$recordModules=[
 ['label'=>'Insured Customers','description'=>'Manage customer and DOT records.','count'=>$counts['Insured Customers'],'url'=>'/agency/crud.php?entity=insureds','symbol'=>'ID'],
 ['label'=>'Vehicles','description'=>'Maintain vehicle and VIN information.','count'=>$counts['Vehicles'],'url'=>'/agency/crud.php?entity=vehicles','symbol'=>'VH'],
 ['label'=>'Policies','description'=>'Manage insurance policies and coverage.','count'=>$counts['Policies'],'url'=>'/agency/crud.php?entity=policies','symbol'=>'PL'],
 ['label'=>'Drivers','description'=>'Maintain drivers and licence details.','count'=>$counts['Drivers'],'url'=>'/agency/crud.php?entity=drivers','symbol'=>'DR']
];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agency Dashboard</title><link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>"></head><body><header class="topbar"><div><strong>ACE Insurance</strong><span>Agency Portal</span></div><div><?=e($user['full_name'])?> · <?=e($user['role'])?> <a class="btn light small-btn" href="<?=e(appUrl('/agency/change-password.php'))?>">Change Password</a><a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a></div></header><div class="layout"><aside class="sidebar"><a class="selected" href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a><a href="<?=e(appUrl('/agency/finance.php'))?>">Finance Billing Manage</a><a href="<?=e(appUrl('/agency/claims.php'))?>">Claims Management</a><?php foreach(['insureds'=>'Insured Customers','vehicles'=>'Vehicles','policies'=>'Policies','drivers'=>'Drivers'] as $k=>$label):?><a href="<?=e(appUrl('/agency/crud.php?entity='.$k))?>"><?=e($label)?></a><?php endforeach;?><?php if($user['role']==='admin'):?><a href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a><a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a><a href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a><?php endif;?></aside><main class="main dashboard-main">
<section class="dashboard-welcome"><div><span class="dashboard-eyebrow">AGENCY OVERVIEW</span><h1>Welcome back, <?=e($user['full_name'])?></h1><p>Manage finance billing, insurance claims and core policy records from one place.</p></div><div class="dashboard-welcome-actions"><a class="btn light" href="<?=e(appUrl('/agency/finance.php?action=add'))?>">+ Add Finance</a><a class="btn primary" href="<?=e(appUrl('/agency/claims.php?action=add'))?>">+ Add Claim</a></div></section>
<section class="dashboard-section"><div class="section-title-row"><div><h2>Operations at a glance</h2><p class="muted">Live record counts and follow-up indicators.</p></div></div>
<div class="dashboard-stats">
<a class="dashboard-stat stat-finance" href="<?=e(appUrl('/agency/finance.php'))?>"><span class="dashboard-stat-icon">FN</span><span class="dashboard-stat-label">Finance Records</span><strong><?=$financeTotal?></strong><small>Open finance billing →</small></a>
<a class="dashboard-stat stat-claims" href="<?=e(appUrl('/agency/claims.php'))?>"><span class="dashboard-stat-icon">CL</span><span class="dashboard-stat-label">Total Claims</span><strong><?=$claimTotal?></strong><small>Open claims register →</small></a>
<a class="dashboard-stat" href="<?=e(appUrl('/agency/claims.php'))?>"><span class="dashboard-stat-icon">IP</span><span class="dashboard-stat-label">Active Claims</span><strong><?=$claimActive?></strong><small>In progress →</small></a>
<a class="dashboard-stat" href="<?=e(appUrl('/agency/finance.php'))?>"><span class="dashboard-stat-icon">PD</span><span class="dashboard-stat-label">Past-Due Finance</span><strong><?=$financePastDue?></strong><small>$<?=number_format($financePastDueAmount,2)?> pending →</small></a>
<a class="dashboard-stat" href="#expiring-policies"><span class="dashboard-stat-icon">EX</span><span class="dashboard-stat-label">Policies Expiring</span><strong><?=$policyExpiringCount?></strong><small>Within next 7 days →</small></a>
<a class="dashboard-stat" href="<?=e(appUrl('/agency/finance.php'))?>"><span class="dashboard-stat-icon">7D</span><span class="dashboard-stat-label">Due in Next 7 Days</span><strong><?=$financeDueSoon?></strong><small>Review due dates →</small></a>
<a class="dashboard-stat" href="<?=e(appUrl('/agency/claims.php?status=Closed'))?>"><span class="dashboard-stat-icon">OK</span><span class="dashboard-stat-label">Closed Claims</span><strong><?=$claimClosed?></strong><small>View completed claims →</small></a>
</div></section>
<section class="dashboard-section" id="expiring-policies">
<div class="section-title-row">
 <div><h2>Policies Expiring in Next 7 Days</h2><p class="muted">Active and draft policies with an upcoming expiry in the next seven calendar days, including coverage-specific end dates.</p></div>
 <a class="btn light small-btn" href="<?=e(appUrl('/agency/crud.php?entity=policies'))?>">View All Policies</a>
</div>
<div class="module-card">
 <?php if ($expiringPolicies): ?>
 <div class="table-wrap">
  <table>
   <thead><tr><th>Customer / Insured</th><th>DOT Number</th><th>Policy Number</th><th>Insurance Carrier</th><th>Status</th><th>Expiry Date</th><th>Time Left</th><th>Contact</th></tr></thead>
   <tbody>
    <?php foreach ($expiringPolicies as $policy): ?>
     <tr>
      <td><strong><?=e((string)$policy['legal_name'])?></strong></td>
      <td><?=e((string)$policy['dot_number'])?></td>
      <td><?=e((string)$policy['policy_number'])?></td>
      <td><?=e((string)$policy['carrier_name'])?></td>
      <td><?=e(ucfirst((string)$policy['status']))?></td>
      <td><strong><?=e(date('M j, Y',strtotime((string)$policy['expiration_date'])))?></strong></td>
      <td><strong><?=((int)$policy['days_remaining'])===0?'Expires today':((int)$policy['days_remaining']).' day(s)'?></strong></td>
      <td><?php if (!empty($policy['email'])): ?><a href="mailto:<?=e((string)$policy['email'])?>"><?=e((string)$policy['email'])?></a><?php endif; ?><?php if (!empty($policy['phone'])): ?><?=!empty($policy['email'])?' · ':''?><?=e((string)$policy['phone'])?><?php endif; ?><?php if (empty($policy['email'])&&empty($policy['phone'])): ?>—<?php endif; ?></td>
     </tr>
    <?php endforeach; ?>
   </tbody>
  </table>
 </div>
 <?php else: ?>
  <p class="muted" style="margin:0">No active policies are expiring within the next 7 days.</p>
 <?php endif; ?>
</div>
</section>
<section class="dashboard-section"><div class="section-title-row"><div><h2>Finance & Claims</h2><p class="muted">Primary work areas for billing and claim handling.</p></div></div><div class="dashboard-work-grid">
<a class="dashboard-work-card dashboard-work-finance" href="<?=e(appUrl('/agency/finance.php'))?>"><div class="dashboard-work-top"><span class="dashboard-work-icon">FN</span><span class="dashboard-open-link">Open module ↗</span></div><h3>Finance Billing Manage</h3><p>Manage finance companies, installments, monthly amounts, due dates, autopay and past-due balances.</p><div class="dashboard-work-footer"><span><strong><?=$financeTotal?></strong> records</span><span><strong><?=$financePastDue?></strong> past due</span><span><strong><?=$financeDueSoon?></strong> due soon</span></div></a>
<a class="dashboard-work-card dashboard-work-claims" href="<?=e(appUrl('/agency/claims.php'))?>"><div class="dashboard-work-top"><span class="dashboard-work-icon">CL</span><span class="dashboard-open-link">Open module ↗</span></div><h3>Claims Management</h3><p>Track insured customers, loss dates, coverage, adjusters, status, latest updates and next actions.</p><div class="dashboard-work-footer"><span><strong><?=$claimTotal?></strong> total</span><span><strong><?=$claimActive?></strong> active</span><span><strong><?=$claimClosed?></strong> closed</span></div></a>
</div></section>
<section class="dashboard-section"><div class="section-title-row"><div><h2>Core Records</h2><p class="muted">Customer, vehicle, policy and driver records used throughout the portal.</p></div></div><div class="dashboard-record-grid"><?php foreach($recordModules as $m):?><a class="dashboard-record-card" href="<?=e(appUrl($m['url']))?>"><span class="dashboard-record-symbol"><?=e($m['symbol'])?></span><span class="dashboard-record-copy"><strong><?=e($m['label'])?></strong><small><?=e($m['description'])?></small></span><span class="dashboard-record-count"><?=$m['count']?></span><span class="dashboard-record-arrow">→</span></a><?php endforeach;?></div></section>
</main></div></body></html>