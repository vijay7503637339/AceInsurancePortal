<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;

$u = Auth::requireRoles(['admin']);

$actorFilter = trim((string)($_GET['actor'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$entityFilter = trim((string)($_GET['entity'] ?? ''));

$conditions = [];
$params = [];

if ($actorFilter === 'system') {
    $conditions[] = 'a.actor_user_id IS NULL';
} elseif ($actorFilter !== '') {
    if (ctype_digit($actorFilter) && (int)$actorFilter > 0) {
        $conditions[] = 'a.actor_user_id = :actor';
        $params['actor'] = (int)$actorFilter;
    } else {
        $actorFilter = '';
    }
}
if ($roleFilter === '__system__') {
    $conditions[] = "(a.actor_role IS NULL OR a.actor_role = '')";
} elseif ($roleFilter !== '') {
    $conditions[] = 'a.actor_role = :role';
    $params['role'] = $roleFilter;
}
if ($actionFilter !== '') {
    $conditions[] = 'a.action = :action';
    $params['action'] = $actionFilter;
}
if ($entityFilter !== '') {
    $conditions[] = 'a.entity_type = :entity';
    $params['entity'] = $entityFilter;
}

$fromWhere = ' FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id';
if ($conditions) {
    $fromWhere .= ' WHERE '.implode(' AND ', $conditions);
}

$countQuery = db()->prepare('SELECT COUNT(*)'.$fromWhere);
$countQuery->execute($params);
$matchedCount = (int)$countQuery->fetchColumn();

// Server-side pagination keeps each request lightweight even when the log table grows.
$perPage = 50;
$requestedPage = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT);
$page = ($requestedPage !== false && $requestedPage !== null && $requestedPage > 0) ? $requestedPage : 1;
$totalPages = max(1, (int)ceil($matchedCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logQuery = db()->prepare('SELECT a.*, u.full_name AS actor_name'.$fromWhere.' ORDER BY a.id DESC LIMIT '.$perPage.' OFFSET '.$offset);
$logQuery->execute($params);
$logs = $logQuery->fetchAll();

$rangeStart = $matchedCount === 0 ? 0 : $offset + 1;
$rangeEnd = min($offset + count($logs), $matchedCount);

$paginationFilters = [];
foreach (['actor' => $actorFilter, 'role' => $roleFilter, 'action' => $actionFilter, 'entity' => $entityFilter] as $filterName => $filterValue) {
    if ($filterValue !== '') {
        $paginationFilters[$filterName] = $filterValue;
    }
}

if ($totalPages <= 7) {
    $pageItems = range(1, $totalPages);
} elseif ($page <= 4) {
    $pageItems = [1, 2, 3, 4, 5, 'ellipsis', $totalPages];
} elseif ($page >= $totalPages - 3) {
    $pageItems = [1, 'ellipsis', $totalPages - 4, $totalPages - 3, $totalPages - 2, $totalPages - 1, $totalPages];
} else {
    $pageItems = [1, 'ellipsis', $page - 1, $page, $page + 1, 'ellipsis', $totalPages];
}

$roles = db()->query("SELECT DISTINCT NULLIF(actor_role, '') AS actor_role FROM audit_logs ORDER BY actor_role")->fetchAll(PDO::FETCH_COLUMN);
$actions = db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$entities = db()->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
$actorOptions = db()->query(
    "SELECT a.actor_user_id,
        COALESCE(MAX(u.full_name), CASE WHEN a.actor_user_id IS NULL THEN 'System / Unknown' ELSE CONCAT('Deleted user #', a.actor_user_id) END) AS actor_name,
        GROUP_CONCAT(DISTINCT a.actor_role ORDER BY a.actor_role SEPARATOR ', ') AS actor_roles
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.actor_user_id
     GROUP BY a.actor_user_id
     ORDER BY actor_name"
)->fetchAll();

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activity Logs</title>
<link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>">
</head>
<body>
<header class="topbar">
  <div><strong>ACE Insurance</strong><span>Agency Portal</span></div>
  <div>
    <?=e($u['full_name'])?> · Admin
    <a class="btn light small-btn" href="<?=e(appUrl('/agency/change-password.php'))?>">Change Password</a>
    <a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a>
  </div>
</header>
<div class="layout">
  <aside class="sidebar">
    <a href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a>
    <a href="<?=e(appUrl('/agency/finance.php'))?>">Finance Billing Manage</a>
    <a href="<?=e(appUrl('/agency/claims.php'))?>">Claims Management</a>
    <a href="<?=e(appUrl('/agency/crud.php?entity=insureds'))?>">Insureds</a>
    <a href="<?=e(appUrl('/agency/crud.php?entity=vehicles'))?>">Vehicles</a>
    <a href="<?=e(appUrl('/agency/crud.php?entity=policies'))?>">Policies</a>
    <a href="<?=e(appUrl('/agency/crud.php?entity=drivers'))?>">Drivers</a>
    <a href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a>
    <a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a>
    <a class="selected" href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a>
  </aside>
  <main class="main audit-log-main">
    <div class="page-title">
      <div><h1>Activity Logs</h1><p class="muted">Admins only. Filter activity by actor, role, action or entity.</p></div>
    </div>
    <section class="panel audit-log-panel">
      <form class="audit-filter-form" method="get" action="<?=e(appUrl('/agency/audit-logs.php'))?>">
        <div class="audit-filters-grid">
          <label>Actor
            <select name="actor">
              <option value="">All actors</option>
              <?php foreach ($actorOptions as $option): ?>
                <?php $optionValue = $option['actor_user_id'] === null ? 'system' : (string)$option['actor_user_id']; ?>
                <option value="<?=e($optionValue)?>" <?=$actorFilter === $optionValue ? 'selected' : ''?>><?=e((string)$option['actor_name'])?><?=!empty($option['actor_roles']) ? ' · '.e((string)$option['actor_roles']) : ''?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Role
            <select name="role">
              <option value="">All roles</option>
              <?php foreach ($roles as $role): ?>
                <?php if ($role === null || $role === ''): ?>
                  <option value="__system__" <?=$roleFilter === '__system__' ? 'selected' : ''?>>System / Unknown</option>
                <?php else: ?>
                  <option value="<?=e((string)$role)?>" <?=$roleFilter === (string)$role ? 'selected' : ''?>><?=e((string)$role)?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Action
            <select name="action">
              <option value="">All actions</option>
              <?php foreach ($actions as $action): ?>
                <option value="<?=e((string)$action)?>" <?=$actionFilter === (string)$action ? 'selected' : ''?>><?=e((string)$action)?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Entity
            <select name="entity">
              <option value="">All entities</option>
              <?php foreach ($entities as $entity): ?>
                <option value="<?=e((string)$entity)?>" <?=$entityFilter === (string)$entity ? 'selected' : ''?>><?=e((string)$entity)?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="audit-filter-footer">
          <span class="muted audit-result-count">Showing <?=number_format($rangeStart)?>–<?=number_format($rangeEnd)?> of <?=number_format($matchedCount)?> matching logs · <?=number_format($perPage)?> per page</span>
          <div class="audit-filter-actions">
            <a class="btn light" href="<?=e(appUrl('/agency/audit-logs.php'))?>">Reset</a>
            <button class="btn primary" type="submit">Apply Filters</button>
          </div>
        </div>
      </form>
      <div class="table-wrap audit-table-wrap">
        <table>
          <thead><tr><th>Time UTC</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th>Record</th><th>Summary</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($logs as $l): ?>
              <tr>
                <td><?=e((string)$l['created_at'])?></td>
                <td><?=e((string)($l['actor_name'] ?? 'Deleted user'))?></td>
                <td><?=e((string)($l['actor_role'] ?? ''))?></td>
                <td><?=e((string)$l['action'])?></td>
                <td><?=e((string)$l['entity_type'])?></td>
                <td><?=e((string)($l['entity_id'] ?? ''))?></td>
                <td><?=e((string)$l['summary'])?></td>
                <td><?=e((string)($l['ip_address'] ?? ''))?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
              <tr><td colspan="8" class="muted audit-empty">No activity logs match the selected filters.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <nav class="audit-pagination" aria-label="Activity log pages">
          <a class="audit-page-link <?=$page <= 1 ? 'is-disabled' : ''?>" href="<?=e(appUrl('/agency/audit-logs.php?'.http_build_query(array_merge($paginationFilters, ['page' => max(1, $page - 1)]))))?>" <?=$page <= 1 ? 'aria-disabled="true" tabindex="-1"' : ''?>>Previous</a>
          <div class="audit-page-numbers">
            <?php foreach ($pageItems as $pageItem): ?>
              <?php if ($pageItem === 'ellipsis'): ?>
                <span class="audit-page-ellipsis" aria-hidden="true">…</span>
              <?php else: ?>
                <a class="audit-page-link <?=$pageItem === $page ? 'is-current' : ''?>" href="<?=e(appUrl('/agency/audit-logs.php?'.http_build_query(array_merge($paginationFilters, ['page' => $pageItem]))))?>" <?=$pageItem === $page ? 'aria-current="page"' : ''?>><?=number_format((int)$pageItem)?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <a class="audit-page-link <?=$page >= $totalPages ? 'is-disabled' : ''?>" href="<?=e(appUrl('/agency/audit-logs.php?'.http_build_query(array_merge($paginationFilters, ['page' => min($totalPages, $page + 1)]))))?>" <?=$page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : ''?>>Next</a>
        </nav>
      <?php endif; ?>
    </section>
  </main>
</div>
</body>
</html>
