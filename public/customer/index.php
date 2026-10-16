<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;

$u = Auth::requireRoles(['customer']);
$insuredId = (int)($u['insured_id'] ?? 0);
if ($insuredId < 1) {
    http_response_code(403);
    exit('Your customer account is not linked to an insured profile. Please contact your agency.');
}

function customerText(mixed $value): string {
    return e($value === null ? '' : (string)$value);
}
function customerDate(mixed $value): string {
    if (!$value) return '—';
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? customerText($value) : date('M j, Y', $timestamp);
}
function customerMoney(mixed $value): string {
    return $value === null || $value === '' || !is_numeric($value)
        ? '—'
        : '$' . number_format((float)$value, 2);
}
function coveragePairs(array $source, string $prefix = ''): array {
    $pairs = [];
    foreach ($source as $key => $value) {
        $label = trim($prefix . ' ' . ucwords(str_replace(['_', '-'], ' ', (string)$key)));
        if (is_array($value)) {
            $pairs = array_merge($pairs, coveragePairs($value, $label));
        } elseif (is_scalar($value) && (string)$value !== '') {
            $pairs[] = [$label, (string)$value];
        }
    }
    return $pairs;
}

$q = db()->prepare('SELECT * FROM insureds WHERE id = :id LIMIT 1');
$q->execute(['id' => $insuredId]);
$insured = $q->fetch();
if (!$insured) {
    http_response_code(404);
    exit('Insured profile not found. Please contact your agency.');
}

$q = db()->prepare('SELECT * FROM policies WHERE insured_id = :id ORDER BY effective_date DESC, id DESC');
$q->execute(['id' => $insuredId]);
$policies = $q->fetchAll();

$tab = (string)($_GET['tab'] ?? 'policies');
if (!in_array($tab, ['policies', 'certificate', 'cab-card'], true)) $tab = 'policies';

$selectedPolicy = null;
$requestedPolicyId = filter_var($_GET['policy_id'] ?? null, FILTER_VALIDATE_INT);
if ($requestedPolicyId) {
    foreach ($policies as $candidate) {
        if ((int)$candidate['id'] === (int)$requestedPolicyId) {
            $selectedPolicy = $candidate;
            break;
        }
    }
}
if (!$selectedPolicy && $policies) $selectedPolicy = $policies[0];

$vehicles = [];
$drivers = [];
$coverages = [];
if ($selectedPolicy) {
    $vehicleQ = db()->prepare(
        'SELECT v.id, v.vin, v.unit_number, v.vehicle_type, v.make, v.model, v.model_year, v.value_amount
         FROM policy_vehicles pv
         INNER JOIN vehicles v ON v.id = pv.vehicle_id
         WHERE pv.policy_id = :policy_id AND v.insured_id = :insured_id AND v.status = "active"
         ORDER BY v.id DESC'
    );
    $vehicleQ->execute(['policy_id' => $selectedPolicy['id'], 'insured_id' => $insuredId]);
    $vehicles = $vehicleQ->fetchAll();
    if (!$vehicles) {
        // The current agency form links vehicles to insureds. Until individual policy assignments are made,
        // show active vehicles owned by this insured.
        $vehicleQ = db()->prepare(
            'SELECT id, vin, unit_number, vehicle_type, make, model, model_year, value_amount
             FROM vehicles WHERE insured_id = :insured_id AND status = "active" ORDER BY id DESC'
        );
        $vehicleQ->execute(['insured_id' => $insuredId]);
        $vehicles = $vehicleQ->fetchAll();
    }

    $driverQ = db()->prepare(
        'SELECT d.id, d.full_name, d.license_number, d.license_state, d.is_excluded
         FROM policy_drivers pd
         INNER JOIN drivers d ON d.id = pd.driver_id
         WHERE pd.policy_id = :policy_id AND d.insured_id = :insured_id AND d.status = "active"
         ORDER BY d.full_name'
    );
    $driverQ->execute(['policy_id' => $selectedPolicy['id'], 'insured_id' => $insuredId]);
    $drivers = $driverQ->fetchAll();
    if (!$drivers) {
        // Agency driver records currently belong to an insured; use those records as the fallback.
        $driverQ = db()->prepare(
            'SELECT id, full_name, license_number, license_state, is_excluded
             FROM drivers WHERE insured_id = :insured_id AND status = "active" ORDER BY full_name'
        );
        $driverQ->execute(['insured_id' => $insuredId]);
        $drivers = $driverQ->fetchAll();
    }

    if (!empty($selectedPolicy['coverage_data'])) {
        $decoded = json_decode((string)$selectedPolicy['coverage_data'], true);
        if (is_array($decoded)) $coverages = coveragePairs($decoded);
    }
}

$insuredAddress = implode(', ', array_filter([
    trim((string)($insured['address_line1'] ?? '')),
    trim((string)($insured['address_line2'] ?? '')),
    trim((string)($insured['city'] ?? '')),
    trim((string)($insured['state'] ?? '')),
    trim((string)($insured['postal_code'] ?? '')),
    trim((string)($insured['country'] ?? '')),
], static fn($v) => $v !== ''));
$premiumTotal = $selectedPolicy['total_premium'] ?? null;
if (($premiumTotal === null || $premiumTotal === '') &&
    is_numeric($selectedPolicy['written_premium'] ?? null)) {
    $premiumTotal = (float)$selectedPolicy['written_premium']
        + (float)($selectedPolicy['estimated_fee'] ?? 0)
        + (float)($selectedPolicy['estimated_tax'] ?? 0);
}
$flash = getFlash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Customer Portal · Ace Insurance</title>
<link rel="stylesheet" href="<?= customerText(appUrl('/assets/app.css')) ?>">
</head>
<body class="customer-portal">
<header class="topbar customer-topbar">
    <div class="customer-brand"><strong>ACE Insurance</strong><span>Customer Portal</span></div>
    <div class="customer-user"><span><?= customerText($u['full_name'] ?? '') ?></span><a class="btn light small-btn" href="<?= customerText(appUrl('/logout.php')) ?>">Logout</a></div>
</header>

<nav class="customer-nav" aria-label="Customer portal sections">
    <a class="<?= $tab === 'policies' ? 'active' : '' ?>" href="<?= customerText(appUrl('/customer/index.php?tab=policies')) ?>">▤ <span>Policies</span></a>
    <a class="<?= $tab === 'certificate' ? 'active' : '' ?>" href="<?= customerText(appUrl('/customer/index.php?tab=certificate')) ?>">▧ <span>Certificate</span></a>
    <a class="<?= $tab === 'cab-card' ? 'active' : '' ?>" href="<?= customerText(appUrl('/customer/index.php?tab=cab-card')) ?>">▣ <span>Cab Card</span></a>
</nav>

<main class="customer-main customer-policy-page">
    <?php if ($flash): ?><div class="alert <?= customerText($flash['type']) ?>"><?= customerText($flash['message']) ?></div><?php endif; ?>
    <div class="customer-welcome">
        <div><p class="eyebrow">CUSTOMER ACCOUNT</p><h1><?= customerText($insured['legal_name']) ?></h1><p class="muted">DOT: <?= customerText($insured['dot_number']) ?></p></div>
        <div class="insured-summary"><span>Insured status</span><strong class="status-pill <?= ($insured['status'] ?? '') === 'active' ? 'is-active' : '' ?>"><?= customerText(ucfirst((string)($insured['status'] ?? 'unknown'))) ?></strong></div>
    </div>

    <?php if ($tab === 'policies'): ?>
        <?php if (!$policies): ?>
            <section class="customer-empty panel"><div class="empty-icon">▤</div><h2>No policies available yet</h2><p class="muted">Your agency has not linked a policy to this account yet. Please contact your insurance agency if you believe this is incorrect.</p></section>
        <?php else: ?>
            <?php if (count($policies) > 1): ?>
                <div class="policy-switcher" aria-label="Choose a policy">
                    <?php foreach ($policies as $p): ?>
                        <a class="policy-chip <?= (int)$p['id'] === (int)$selectedPolicy['id'] ? 'active' : '' ?>" href="<?= customerText(appUrl('/customer/index.php?tab=policies&policy_id='.(int)$p['id'])) ?>">
                            <strong><?= customerText($p['policy_number']) ?></strong><span><?= customerText($p['business_line'] ?? 'Insurance policy') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <section class="policy-hero panel">
                <div><span class="eyebrow">POLICY NUMBER</span><h2><?= customerText($selectedPolicy['policy_number']) ?></h2><p><?= customerText($selectedPolicy['business_line'] ?: 'Insurance Policy') ?></p></div>
                <div class="premium-highlight"><span>Total Premium</span><strong><?= customerMoney($premiumTotal) ?></strong><span class="status-pill <?= ($selectedPolicy['status'] ?? '') === 'active' ? 'is-active' : '' ?>"><?= customerText(ucfirst((string)$selectedPolicy['status'])) ?></span></div>
            </section>

            <div class="policy-info-grid">
                <section class="info-card"><h2>Insurance Details</h2>
                    <dl class="detail-list">
                        <div><dt>Insurance Company</dt><dd><?= customerText($selectedPolicy['carrier_name'] ?: 'Not provided') ?></dd></div>
                        <div><dt>Broker</dt><dd><?= customerText($selectedPolicy['broker_name'] ?: 'Not provided') ?></dd></div>
                        <div><dt>Finance Company</dt><dd><?= customerText($selectedPolicy['finance_company'] ?: 'Not provided') ?></dd></div>
                        <div><dt>Business Line</dt><dd><?= customerText($selectedPolicy['business_line'] ?: 'Not provided') ?></dd></div>
                    </dl>
                </section>
                <section class="info-card"><h2>Policy Period</h2>
                    <dl class="detail-list">
                        <div><dt>Effective Date</dt><dd><?= customerDate($selectedPolicy['effective_date']) ?></dd></div>
                        <div><dt>Expiration Date</dt><dd><?= customerDate($selectedPolicy['expiration_date']) ?></dd></div>
                        <div><dt>Policy Number</dt><dd><?= customerText($selectedPolicy['policy_number']) ?></dd></div>
                        <div><dt>Certificate Number</dt><dd><?= customerText($selectedPolicy['certificate_number'] ?: '—') ?></dd></div>
                    </dl>
                </section>
                <section class="info-card premium-card"><h2>Premium</h2>
                    <dl class="detail-list">
                        <div><dt>Written Premium</dt><dd><?= customerMoney($selectedPolicy['written_premium']) ?></dd></div>
                        <div><dt>Estimated Fee</dt><dd><?= customerMoney($selectedPolicy['estimated_fee']) ?></dd></div>
                        <div><dt>Estimated Tax</dt><dd><?= customerMoney($selectedPolicy['estimated_tax']) ?></dd></div>
                        <div class="detail-total"><dt>Total Premium</dt><dd><?= customerMoney($premiumTotal) ?></dd></div>
                    </dl>
                </section>
                <section class="info-card"><h2>Insured Details</h2>
                    <dl class="detail-list">
                        <div><dt>Legal Name</dt><dd><?= customerText($insured['legal_name']) ?></dd></div>
                        <div><dt>DBA Name</dt><dd><?= customerText($insured['dba_name'] ?: '—') ?></dd></div>
                        <div><dt>DOT Number</dt><dd><?= customerText($insured['dot_number']) ?></dd></div>
                        <div><dt>Address</dt><dd><?= customerText($insuredAddress ?: '—') ?></dd></div>
                        <div><dt>Email</dt><dd><?= customerText($insured['email'] ?: '—') ?></dd></div>
                        <div><dt>Phone</dt><dd><?= customerText($insured['phone'] ?: '—') ?></dd></div>
                    </dl>
                </section>
            </div>

            <div class="policy-bottom-grid">
                <section class="panel policy-table-panel"><div class="section-heading"><h2>Vehicles <span class="count-pill"><?= count($vehicles) ?></span></h2><span class="muted small">Covered / insured vehicles</span></div>
                    <div class="table-wrap"><table><thead><tr><th>VIN</th><th>Make</th><th>Model</th><th>Year</th><th>Unit</th><th>Value</th></tr></thead><tbody>
                    <?php foreach ($vehicles as $vehicle): ?><tr><td class="mono"><?= customerText($vehicle['vin']) ?></td><td><?= customerText($vehicle['make'] ?: '—') ?></td><td><?= customerText($vehicle['model'] ?: '—') ?></td><td><?= customerText($vehicle['model_year'] ?: '—') ?></td><td><?= customerText($vehicle['unit_number'] ?: '—') ?></td><td><?= customerMoney($vehicle['value_amount']) ?></td></tr><?php endforeach; ?>
                    <?php if (!$vehicles): ?><tr><td colspan="6" class="empty-cell">No vehicles have been added to this insured account.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

                <section class="panel policy-table-panel"><div class="section-heading"><h2>Drivers <span class="count-pill"><?= count($drivers) ?></span></h2><span class="muted small">Drivers on file</span></div>
                    <div class="table-wrap"><table><thead><tr><th>Name</th><th>License</th><th>State</th><th>Excluded</th></tr></thead><tbody>
                    <?php foreach ($drivers as $driver): ?><tr><td><?= customerText($driver['full_name']) ?></td><td><?= customerText($driver['license_number'] ?: '—') ?></td><td><?= customerText($driver['license_state'] ?: '—') ?></td><td><span class="status-pill <?= empty($driver['is_excluded']) ? 'is-active' : 'is-inactive' ?>"><?= empty($driver['is_excluded']) ? 'No' : 'Yes' ?></span></td></tr><?php endforeach; ?>
                    <?php if (!$drivers): ?><tr><td colspan="4" class="empty-cell">No drivers have been added to this insured account.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            </div>

            <section class="panel coverage-panel"><div class="section-heading"><h2>Coverages</h2><span class="muted small">From your policy record</span></div>
                <?php if ($coverages): ?><div class="coverage-grid"><?php foreach ($coverages as [$label,$value]): ?><div class="coverage-item"><span><?= customerText($label) ?></span><strong><?= customerText($value) ?></strong></div><?php endforeach; ?></div>
                <?php else: ?><p class="muted coverage-empty">Coverage limits have not been entered for this policy yet.</p><?php endif; ?>
            </section>
        <?php endif; ?>

    <?php elseif ($tab === 'certificate'): ?>
        <section class="panel customer-placeholder"><div class="empty-icon">▧</div><h2>Certificate of Insurance</h2><p class="muted">Certificate preview and download will be connected in the next phase. Your policy information remains available in the Policies tab.</p><a class="btn primary" href="<?= customerText(appUrl('/customer/index.php?tab=policies'.($selectedPolicy ? '&policy_id='.(int)$selectedPolicy['id'] : ''))) ?>">View Policies</a></section>
    <?php else: ?>
        <section class="panel customer-placeholder"><div class="empty-icon">▣</div><h2>Cab Card</h2><p class="muted">Cab card documents and downloads will be connected after the document requirements are confirmed.</p><a class="btn primary" href="<?= customerText(appUrl('/customer/index.php?tab=policies'.($selectedPolicy ? '&policy_id='.(int)$selectedPolicy['id'] : ''))) ?>">View Policies</a></section>
    <?php endif; ?>
</main>
</body>
</html>
