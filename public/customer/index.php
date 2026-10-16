<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
use AcePortal\Auth;
use AcePortal\CertificatePdf;

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


function certificateEmails(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];
    $parts = preg_split('/[,;]+/', $raw) ?: [];
    if (count($parts) > 20) throw new \RuntimeException('Please use no more than 20 email recipients.');
    $emails = [];
    foreach ($parts as $part) {
        $email = trim($part);
        if ($email === '') continue;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            throw new \RuntimeException('One or more email addresses are invalid. Use comma-separated email addresses.');
        }
        $emails[] = strtolower($email);
    }
    return array_values(array_unique($emails));
}

function sendCertificateMail(array $to, array $cc, string $policyNumber, string $insuredName, string $holderName, string $attachmentPath): bool
{
    if (!$to) throw new \RuntimeException('Enter at least one broker email address before sending the certificate.');
    $mailConfig = configFile('mail');
    $from = trim((string)($mailConfig['from_email'] ?? ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('Configure a valid sender email in config/mail.php before sending certificates.');
    }
    if (!is_file($attachmentPath) || !is_readable($attachmentPath)) {
        throw new \RuntimeException('The generated certificate could not be opened for email attachment.');
    }
    $pdf = file_get_contents($attachmentPath);
    if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
        throw new \RuntimeException('The generated certificate attachment is not a valid PDF.');
    }
    $fromName = str_replace(["\r", "\n", '"'], '', (string)($mailConfig['from_name'] ?? 'Ace Insurance Portal'));
    $boundary = '=_AcePortal_' . bin2hex(random_bytes(16));
    $safePolicy = preg_replace('/[^A-Za-z0-9_-]+/', '-', $policyNumber) ?: 'policy';
    $filename = 'certificate-' . trim($safePolicy, '-') . '.pdf';
    $subjectPolicy = str_replace(["\r", "\n"], '', $policyNumber);
    $subject = 'Certificate of Insurance - Policy ' . $subjectPolicy;
    $bodyText = "Hello,\n\nPlease find attached the Certificate of Insurance for policy {$policyNumber}.\nInsured: {$insuredName}\nCertificate holder: {$holderName}\n\nThis document was generated from the policy information currently on file. Please contact the issuing agency if any corrections are required.\n";
    $headers = [
        'From: ' . $fromName . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    if ($cc) $headers[] = 'Cc: ' . implode(', ', $cc);
    $body = '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $bodyText . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: application/pdf; name="' . $filename . '"' . "\r\n";
    $body .= 'Content-Transfer-Encoding: base64' . "\r\n";
    $body .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n\r\n";
    $body .= chunk_split(base64_encode($pdf)) . "\r\n";
    $body .= '--' . $boundary . "--\r\n";
    return mail(implode(', ', $to), $subject, $body, implode("\r\n", $headers));
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

// Generated certificates stay private and are accessible only to the customer session
// that generated them. Never accept arbitrary filesystem paths from request parameters.
if (isset($_GET['file'])) {
    $requestedFile = is_string($_GET['file']) ? $_GET['file'] : '';
    $fileId = preg_match('/^[a-f0-9]{32}$/', $requestedFile) ? $requestedFile : '';
    $fileRecord = $fileId !== '' ? ($_SESSION['certificate_files'][$fileId] ?? null) : null;
    if (!is_array($fileRecord) || (int)($fileRecord['insured_id'] ?? 0) !== $insuredId) {
        http_response_code(404);
        exit('Certificate not found. Generate the preview again from your account.');
    }
    $policyCheck = db()->prepare('SELECT policy_number FROM policies WHERE id = :id AND insured_id = :insured_id LIMIT 1');
    $policyCheck->execute(['id' => (int)$fileRecord['policy_id'], 'insured_id' => $insuredId]);
    $filePolicy = $policyCheck->fetch();
    $privatePath = APP_ROOT . '/storage/private/' . $fileId . '.pdf';
    if (!$filePolicy || !is_file($privatePath) || !is_readable($privatePath)) {
        http_response_code(404);
        exit('Certificate file not found. Generate the preview again.');
    }
    $safePolicy = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$filePolicy['policy_number']) ?: 'policy';
    $filename = 'certificate-' . trim($safePolicy, '-') . '.pdf';
    $download = (string)($_GET['download'] ?? '') === '1';
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)filesize($privatePath));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    readfile($privatePath);
    exit;
}


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



$certificateErrors = [];
$certificateForm = [
    'broker_emails' => '',
    'cc_emails' => '',
    'broker_name' => trim((string)($selectedPolicy['broker_name'] ?? '')),
    'broker_address' => '',
    'certificate_holder_name' => '',
    'certificate_holder_address' => '',
    'vehicle_ids' => array_map(static fn(array $v): string => (string)$v['id'], $vehicles),
];
$currentPolicyId = $selectedPolicy ? (int)$selectedPolicy['id'] : 0;
if ($currentPolicyId > 0 && isset($_SESSION['certificate_form'][$currentPolicyId]) && is_array($_SESSION['certificate_form'][$currentPolicyId])) {
    $certificateForm = array_merge($certificateForm, $_SESSION['certificate_form'][$currentPolicyId]);
}
$generatedId = null;
$requestedCertificateId = is_string($_GET['certificate_file'] ?? null) ? (string)$_GET['certificate_file'] : '';
if ($currentPolicyId > 0 && preg_match('/^[a-f0-9]{32}$/', $requestedCertificateId)) {
    $record = $_SESSION['certificate_files'][$requestedCertificateId] ?? null;
    if (is_array($record) && (int)($record['insured_id'] ?? 0) === $insuredId && (int)($record['policy_id'] ?? 0) === $currentPolicyId && is_file(APP_ROOT . '/storage/private/' . $requestedCertificateId . '.pdf')) {
        $generatedId = $requestedCertificateId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['certificate_action'])) {
    verifyCsrf();
    try {
        if (!$selectedPolicy || $currentPolicyId < 1) {
            throw new \RuntimeException('Select one of your policies before generating a certificate.');
        }
        $action = (string)($_POST['certificate_action'] ?? 'preview');
        if (!in_array($action, ['preview', 'send'], true)) throw new \RuntimeException('Invalid certificate action.');
        $postedPolicyId = filter_var($_POST['policy_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$postedPolicyId || (int)$postedPolicyId !== $currentPolicyId) {
            throw new \RuntimeException('The selected policy changed. Refresh the page and try again.');
        }

        $certificateForm['broker_emails'] = trim(substr((string)($_POST['broker_emails'] ?? ''), 0, 1200));
        $certificateForm['cc_emails'] = trim(substr((string)($_POST['cc_emails'] ?? ''), 0, 1200));
        $certificateForm['broker_name'] = trim(substr((string)($_POST['broker_name'] ?? ''), 0, 190));
        $certificateForm['broker_address'] = trim(substr((string)($_POST['broker_address'] ?? ''), 0, 700));
        $certificateForm['certificate_holder_name'] = trim(substr((string)($_POST['certificate_holder_name'] ?? ''), 0, 190));
        $certificateForm['certificate_holder_address'] = trim(substr((string)($_POST['certificate_holder_address'] ?? ''), 0, 700));
        $rawVehicleIds = $_POST['vehicle_ids'] ?? [];
        if (!is_array($rawVehicleIds)) $rawVehicleIds = [];
        $allowedVehicleIds = array_map(static fn(array $v): int => (int)$v['id'], $vehicles);
        $selectedVehicleIds = [];
        foreach (array_slice($rawVehicleIds, 0, 100) as $rawId) {
            $vehicleId = filter_var($rawId, FILTER_VALIDATE_INT);
            if (!$vehicleId || !in_array((int)$vehicleId, $allowedVehicleIds, true)) {
                throw new \RuntimeException('One of the selected vehicles is not available under this policy.');
            }
            $selectedVehicleIds[] = (int)$vehicleId;
        }
        $selectedVehicleIds = array_values(array_unique($selectedVehicleIds));
        if (!$selectedVehicleIds) $selectedVehicleIds = $allowedVehicleIds;
        $certificateForm['vehicle_ids'] = array_map('strval', $selectedVehicleIds);
        $_SESSION['certificate_form'][$currentPolicyId] = $certificateForm;

        if ($certificateForm['certificate_holder_name'] === '' || $certificateForm['certificate_holder_address'] === '') {
            throw new \RuntimeException('Enter the certificate holder name and address.');
        }

        $toEmails = [];
        $ccEmails = [];
        if ($action === 'send') {
            $toEmails = certificateEmails($certificateForm['broker_emails']);
            $ccEmails = certificateEmails($certificateForm['cc_emails']);
            if (!$toEmails) throw new \RuntimeException('Enter at least one broker email address.');
            if ($certificateForm['broker_name'] === '' || $certificateForm['broker_address'] === '') {
                throw new \RuntimeException('Enter the broker name and broker address before sending.');
            }
        }

        $selectedVehicles = array_values(array_filter(
            $vehicles,
            static fn(array $v): bool => in_array((int)$v['id'], $selectedVehicleIds, true)
        ));
        $fileId = bin2hex(random_bytes(16));
        $storageDirectory = APP_ROOT . '/storage/private';
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
            throw new \RuntimeException('Could not create private certificate storage. Check folder permissions.');
        }
        $outputPath = $storageDirectory . '/' . $fileId . '.pdf';
        CertificatePdf::generate(
            $selectedPolicy,
            $insured,
            $selectedVehicles,
            $certificateForm,
            configFile('mail'),
            $outputPath
        );
        $_SESSION['certificate_files'][$fileId] = [
            'insured_id' => $insuredId,
            'policy_id' => $currentPolicyId,
            'created_at' => time(),
        ];
        if (count($_SESSION['certificate_files']) > 12) {
            $_SESSION['certificate_files'] = array_slice($_SESSION['certificate_files'], -12, null, true);
        }
        $generatedId = $fileId;

        if ($action === 'send') {
            $mailSent = sendCertificateMail(
                $toEmails,
                $ccEmails,
                (string)$selectedPolicy['policy_number'],
                (string)$insured['legal_name'],
                $certificateForm['certificate_holder_name'],
                $outputPath
            );
            if (!$mailSent) {
                throw new \RuntimeException('The PDF was generated, but the hosting mail service could not send it. Check config/mail.php and cPanel email delivery.');
            }
            audit('certificate_email_sent', 'policy', $currentPolicyId, 'Customer emailed certificate to broker for policy ' . (string)$selectedPolicy['policy_number']);
            flash('success', 'Certificate generated and sent to the broker email address(es).');
        } else {
            audit('certificate_generated', 'policy', $currentPolicyId, 'Customer generated a certificate preview for policy ' . (string)$selectedPolicy['policy_number']);
            flash('success', 'Certificate preview generated from your policy records.');
        }
        redirect('/customer/index.php?tab=certificate&policy_id=' . $currentPolicyId . '&certificate_file=' . $fileId . '#certificate-preview');
    } catch (\Throwable $exception) {
        error_log('Customer certificate action failed: ' . $exception->getMessage());
        $certificateErrors[] = $exception instanceof \RuntimeException
            ? $exception->getMessage()
            : 'Certificate could not be generated. Please check the server error log or contact your agency.';
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
        <?php if (!$policies || !$selectedPolicy): ?>
            <section class="panel customer-placeholder"><div class="empty-icon">▧</div><h2>No policy available</h2><p class="muted">A certificate can be generated after your agency adds a policy to your account.</p></section>
        <?php else: ?>
            <div class="certificate-topline">
                <div><p class="eyebrow">CERTIFICATE OF INSURANCE</p><h2><?= customerText($selectedPolicy['policy_number']) ?></h2><p class="muted"><?= customerText($selectedPolicy['carrier_name'] ?: 'Insurance company not provided') ?> · <?= customerDate($selectedPolicy['effective_date']) ?> – <?= customerDate($selectedPolicy['expiration_date']) ?></p></div>
                <?php if (count($policies) > 1): ?><form method="get" class="policy-select-form"><input type="hidden" name="tab" value="certificate"><label for="certificate-policy">Policy</label><select id="certificate-policy" name="policy_id" onchange="this.form.submit()"><?php foreach ($policies as $policyOption): ?><option value="<?= (int)$policyOption['id'] ?>" <?= (int)$policyOption['id'] === (int)$selectedPolicy['id'] ? 'selected' : '' ?>><?= customerText($policyOption['policy_number']) ?> · <?= customerText($policyOption['business_line'] ?? 'Policy') ?></option><?php endforeach; ?></select></form><?php endif; ?>
            </div>
            <div class="certificate-layout">
                <section class="panel certificate-form-panel">
                    <div class="panel-heading"><h2>Send to Broker</h2><span>Policy data is read-only</span></div>
                    <?php foreach ($certificateErrors as $certificateError): ?><div class="alert error certificate-error"><?= customerText($certificateError) ?></div><?php endforeach; ?>
                    <div class="certificate-data-note"><strong>Policy details are loaded automatically.</strong> Insured, carrier, policy number and dates come from your saved insurance records. Enter certificate holder and delivery information below.</div>
                    <form method="post" action="<?= customerText(appUrl('/customer/index.php?tab=certificate&policy_id='.(int)$selectedPolicy['id'])) ?>" class="certificate-form">
                        <input type="hidden" name="_csrf" value="<?= customerText(csrfToken()) ?>">
                        <input type="hidden" name="policy_id" value="<?= (int)$selectedPolicy['id'] ?>">
                        <div class="cert-form-section">EMAIL DELIVERY</div>
                        <label>Broker Email(s)<input type="text" name="broker_emails" value="<?= customerText((string)$certificateForm['broker_emails']) ?>" placeholder="broker@example.com, broker2@example.com"><small>Required only when sending. Separate multiple email addresses with commas.</small></label>
                        <label>CC Email(s)<input type="text" name="cc_emails" value="<?= customerText((string)$certificateForm['cc_emails']) ?>" placeholder="cc@example.com, cc2@example.com"><small>Optional. Separate multiple addresses with commas.</small></label>
                        <label>Broker Name<input type="text" name="broker_name" value="<?= customerText((string)$certificateForm['broker_name']) ?>" maxlength="190" placeholder="Broker name"></label>
                        <label>Broker Address<textarea name="broker_address" rows="2" maxlength="700" placeholder="Broker mailing address"><?= customerText((string)$certificateForm['broker_address']) ?></textarea></label>
                        <div class="cert-form-section">CERTIFICATE HOLDER</div>
                        <label>Certificate Holder Name *<input type="text" name="certificate_holder_name" value="<?= customerText((string)$certificateForm['certificate_holder_name']) ?>" required maxlength="190" placeholder="Company or organization name"></label>
                        <label>Certificate Holder Address *<textarea name="certificate_holder_address" rows="3" required maxlength="700" placeholder="Street, city, state and ZIP"><?= customerText((string)$certificateForm['certificate_holder_address']) ?></textarea><small>This name and address are placed in the certificate holder section of the PDF.</small></label>
                        <div class="cert-form-section">VEHICLES TO INCLUDE</div>
                        <label>Vehicles<select name="vehicle_ids[]" multiple size="4" class="vehicle-multiselect"><?php foreach ($vehicles as $vehicleOption): ?><option value="<?= (int)$vehicleOption['id'] ?>" <?= in_array((string)$vehicleOption['id'], array_map('strval', (array)$certificateForm['vehicle_ids']), true) ? 'selected' : '' ?>><?= customerText($vehicleOption['vin']) ?> (<?= customerText(trim(($vehicleOption['model_year'] ?? '').' '.($vehicleOption['make'] ?? '').' '.($vehicleOption['model'] ?? ''))) ?>)</option><?php endforeach; ?></select><small>Use Ctrl (Windows) or Command (Mac) to select multiple vehicles. If none is selected, all available vehicles are included.</small></label>
                        <div class="certificate-form-actions"><button class="btn primary" type="submit" name="certificate_action" value="send">➤ Send to Broker</button><button class="btn secondary" type="submit" name="certificate_action" value="preview">▣ Generate PDF Preview</button></div>
                        <p class="certificate-small-note">Missing policy limits or insurer identifiers stay blank when they are not present in the database.</p>
                    </form>
                </section>
                <section class="panel certificate-preview-panel" id="certificate-preview">
                    <div class="panel-heading"><h2>PDF Preview</h2><span><?= $generatedId ? 'Generated from policy data' : 'Preview will appear here' ?></span></div>
                    <div class="certificate-preview-inner">
                        <div class="preview-status"><span class="status-pill <?= $generatedId ? 'is-active' : '' ?>"><?= $generatedId ? '● Preview ready' : 'Waiting for preview' ?></span><span class="muted small">ACORD 25 · US Letter</span></div>
                        <?php if ($generatedId): ?>
                            <iframe id="certificatePreviewFrame" class="certificate-pdf-frame" title="Certificate of Insurance PDF preview" src="<?= customerText(appUrl('/customer/index.php?file='.$generatedId)) ?>#toolbar=1&navpanes=0"></iframe>
                            <div class="certificate-preview-actions"><a class="btn primary" href="<?= customerText(appUrl('/customer/index.php?file='.$generatedId.'&download=1')) ?>">↓ Download PDF</a><a class="btn secondary" href="<?= customerText(appUrl('/customer/index.php?file='.$generatedId)) ?>" target="_blank" rel="noopener">⎙ Print Certificate</a></div>
                        <?php else: ?>
                            <div class="certificate-preview-empty"><div class="empty-icon">▧</div><h3>Certificate preview</h3><p class="muted">Enter the certificate holder information, then select Generate PDF Preview. Policy number, carrier and dates will be taken from your saved policy.</p></div>
                        <?php endif; ?>
                    </div>
                    <div class="certificate-footnote">Technical PDF generation only. The issuing agency must review and authorize certificate content before it is relied upon as evidence of coverage.</div>
                </section>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <section class="panel customer-placeholder"><div class="empty-icon">▣</div><h2>Cab Card</h2><p class="muted">Cab card documents and downloads will be connected after the document requirements are confirmed.</p><a class="btn primary" href="<?= customerText(appUrl('/customer/index.php?tab=policies'.($selectedPolicy ? '&policy_id='.(int)$selectedPolicy['id'] : ''))) ?>">View Policies</a></section>
    <?php endif; ?>
</main>
</body>
</html>
