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
    $technicalKeys = ['insurance_types', 'insurance_carriers', 'coverage_options'];
    $friendlyLabels = [
        'types_of_insurance_summary' => 'Types of Insurance',
        'coverage_options_summary' => 'Coverage Options',
        'commercial_general_liability' => 'Commercial General Liability',
        'physical_damage' => 'Physical Damage',
        'motor_truck_cargo' => 'Motor Truck Cargo',
        'policy_number' => 'Policy Number',
        'effective_date' => 'Policy Start Date',
        'expiration_date' => 'Policy End Date',
        'limits' => 'Limits',
        'each_occurrence' => 'Each Occurrence',
        'damage_to_rented_premises' => 'Damage to Rented Premises',
        'medical_expense' => 'Medical Expense',
        'personal_advertising_injury' => 'Personal & Advertising Injury',
        'general_aggregate' => 'General Aggregate',
        'products_completed_operations_aggregate' => 'Products - Completed Operations Aggregate',
        'combined_single_limit' => 'Combined Single Limit',
        'amount' => 'Amount / Limit',
        'description' => 'Description',
        'deductible_retention_amount' => 'Deductible / Retention Amount',
        'aggregate_other_description' => 'General Aggregate Other',
        'workers_compensation_other_text' => 'Workers Compensation Other',
        'workers_compensation_excluded' => "Proprietor / Executive Officer Excluded",
    ];
    foreach ($source as $key => $value) {
        $key = (string)$key;
        if (in_array($key, $technicalKeys, true)) continue;
        $label = trim($prefix . ' ' . ($friendlyLabels[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key))));
        if (is_array($value)) {
            $pairs = array_merge($pairs, coveragePairs($value, $label));
        } elseif (is_scalar($value) && (string)$value !== '') {
            $displayValue = (string)$value;
            if ($key === 'combined_single_limit' && is_numeric($displayValue)) {
                $decimals = str_contains($displayValue, '.') ? 2 : 0;
                $displayValue = number_format((float)$displayValue, $decimals, '.', ',');
            } elseif ($key === 'workers_compensation_excluded') {
                $displayValue = ['yes' => 'Yes', 'no' => 'No', 'na' => 'N/A'][$displayValue] ?? $displayValue;
            }
            $pairs[] = [$label, $displayValue];
        }
    }
    return $pairs;
}



function customerCoverageAmount(mixed $value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) return trim((string)$value);
    return '$' . number_format((float)$value, 0);
}

function customerCoverageCards(array $policy): array
{
    $decoded = json_decode((string)($policy['coverage_data'] ?? ''), true);
    if (!is_array($decoded)) return [];

    $labels = [
        'commercial_general_liability' => 'Commercial General Liability',
        'automobile_liability' => 'Automobile Liability',
        'physical_damage' => 'Physical Damage',
        'motor_truck_cargo' => 'Motor Truck Cargo',
        'umbrella_liability' => 'Umbrella Liability',
        'excess_liability' => 'Excess Liability',
        'workers_compensation_employers_liability' => "Workers Compensation & Employers' Liability",
    ];
    $symbols = [
        'commercial_general_liability' => 'GL',
        'automobile_liability' => 'AL',
        'physical_damage' => 'PD',
        'motor_truck_cargo' => 'MC',
        'umbrella_liability' => 'UL',
        'excess_liability' => 'EX',
        'workers_compensation_employers_liability' => 'WC',
    ];
    $optionLabels = [
        'claims_made' => 'Claims-Made', 'occur' => 'Occurrence',
        'aggregate_policy' => 'Policy Aggregate', 'aggregate_project' => 'Project Aggregate',
        'aggregate_location' => 'Location Aggregate', 'any_auto' => 'Any Auto',
        'all_owned_autos' => 'All Owned Autos', 'hired_autos' => 'Hired Autos',
        'scheduled_autos' => 'Scheduled Autos', 'non_owned_autos' => 'Non-Owned Autos',
        'deductible' => 'Deductible', 'retention' => 'Retention',
        'per_statute' => 'Per Statute', 'other' => 'Other',
    ];
    $limitLabels = [
        'each_occurrence' => 'Each Occurrence',
        'damage_to_rented_premises' => 'Damage to Rented Premises',
        'medical_expense' => 'Medical Expense',
        'personal_advertising_injury' => 'Personal & Advertising Injury',
        'general_aggregate' => 'General Aggregate',
        'products_completed_operations_aggregate' => 'Products / Completed Operations Aggregate',
    ];
    $types = is_array($decoded['insurance_types'] ?? null) ? $decoded['insurance_types'] : [];
    $carriers = is_array($decoded['insurance_carriers'] ?? null) ? $decoded['insurance_carriers'] : [];
    $savedOptions = is_array($decoded['coverage_options'] ?? null) ? $decoded['coverage_options'] : [];
    $today = date('Y-m-d');
    $cards = [];

    foreach (array_values(array_unique(array_map('strval', $types))) as $type) {
        if (!isset($labels[$type])) continue;
        $data = is_array($decoded[$type] ?? null) ? $decoded[$type] : [];
        $startDate = trim((string)($data['effective_date'] ?? ''));
        $endDate = trim((string)($data['expiration_date'] ?? ''));
        $policyNumber = trim((string)($data['policy_number'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $highlights = $details = [];

        if ($type === 'automobile_liability') {
            // Older records store automobile liability dates and number on the parent policy.
            $startDate = $startDate !== '' ? $startDate : (string)($policy['effective_date'] ?? '');
            $endDate = $endDate !== '' ? $endDate : (string)($policy['expiration_date'] ?? '');
            $policyNumber = $policyNumber !== '' ? $policyNumber : (string)($policy['policy_number'] ?? '');
            $limit = trim((string)($decoded['combined_single_limit'] ?? ''));
            if ($limit !== '') {
                $formatted = customerCoverageAmount($limit);
                $highlights[] = ['Combined Single Limit', $formatted];
                $details[] = ['Combined Single Limit (Each Accident)', $formatted];
            }
        } elseif ($type === 'commercial_general_liability') {
            $limits = is_array($data['limits'] ?? null) ? $data['limits'] : [];
            foreach ($limitLabels as $key => $label) {
                $value = trim((string)($limits[$key] ?? ''));
                if ($value === '') continue;
                $formatted = customerCoverageAmount($value);
                $details[] = [$label, $formatted];
                if (in_array($key, ['each_occurrence', 'general_aggregate'], true)) {
                    $highlights[] = [$label, $formatted];
                }
            }
        } elseif (in_array($type, ['physical_damage', 'motor_truck_cargo'], true)) {
            $amount = trim((string)($data['amount'] ?? ''));
            if ($amount !== '') {
                $formatted = customerCoverageAmount($amount);
                $highlights[] = ['Coverage Limit', $formatted];
                $details[] = ['Amount / Limit', $formatted];
            }
            if ($description !== '') $details[] = ['Description', $description];
        }

        $options = [];
        foreach ((array)($savedOptions[$type] ?? []) as $option) {
            $option = (string)$option;
            if ($option !== '') $options[] = $optionLabels[$option] ?? ucwords(str_replace('_', ' ', $option));
        }

        $carrierLetter = strtoupper(trim((string)($carriers[$type] ?? '')));
        $carrierName = '';
        if (preg_match('/^[A-F]$/', $carrierLetter)) {
            $carrierName = trim((string)($policy['insurer_' . strtolower($carrierLetter) . '_name'] ?? ''));
        }
        if ($carrierName === '' && $type === 'automobile_liability') {
            $carrierName = trim((string)($policy['carrier_name'] ?? ''));
        }

        if ($startDate !== '' && $endDate !== '') {
            if ($endDate < $today) { $status = 'Expired'; $statusClass = 'is-expired'; }
            elseif ($startDate > $today) { $status = 'Upcoming'; $statusClass = 'is-upcoming'; }
            else { $status = 'Active'; $statusClass = 'is-active'; }
        } else {
            $status = 'Dates not added';
            $statusClass = 'is-incomplete';
        }

        $cards[] = [
            'key' => $type, 'symbol' => $symbols[$type] ?? 'IN',
            'name' => $labels[$type], 'policy_number' => $policyNumber,
            'start_date' => $startDate, 'end_date' => $endDate,
            'status' => $status, 'status_class' => $statusClass,
            'carrier' => $carrierName, 'highlights' => $highlights,
            'options' => array_values(array_unique($options)),
            'details' => $details, 'description' => $description,
        ];
    }
    return $cards;
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

function sendCertificateMail(array $to, array $cc, string $policyNumber, string $insuredName, string $insuredAddress, string $holderName, array $vehicles, array $companySettings, string $attachmentPath): bool
{
    if (!$to) throw new \RuntimeException('Enter at least one broker email address before sending the certificate.');
    $mailConfig = configFile('mail');
    $from = trim((string)($mailConfig['from_email'] ?? ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('Configure a valid sender email in config/mail.php before sending certificates.');
    }
    $contactName = trim((string)($companySettings['contact_person'] ?? ''));
    $contactPhone = trim((string)($companySettings['phone_number'] ?? ''));
    $contactEmail = trim((string)($companySettings['email_address'] ?? ''));
    $companyName = trim((string)($companySettings['company_name'] ?? 'ACE Insurance'));
    if ($contactName === '' || $contactPhone === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        throw new \RuntimeException('Please save the Contact Person, Phone Number and Email Address in Admin → Own Company before sending.');
    }
    if (!is_file($attachmentPath) || !is_readable($attachmentPath)) {
        throw new \RuntimeException('The generated certificate could not be opened for email attachment.');
    }
    $pdf = file_get_contents($attachmentPath);
    if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
        throw new \RuntimeException('The generated certificate attachment is not a valid PDF.');
    }

    // ACE Insurance is always copied; any additional CC addresses entered by the customer are retained.
    $cc = array_values(array_unique(array_merge($cc, ['info@AceInsure.com'])));
    $fromName = 'ACE Insurance';
    $boundary = '=_AcePortal_' . bin2hex(random_bytes(16));
    $alternativeBoundary = '=_AcePortalAlt_' . bin2hex(random_bytes(16));
    $safePolicy = preg_replace('/[^A-Za-z0-9_-]+/', '-', $policyNumber) ?: 'policy';
    $filename = 'certificate-' . trim($safePolicy, '-') . '.pdf';
    $subject = 'Certificate of Insurance - ' . trim(str_replace(["\r", "\n"], '', $insuredName));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $escapeHtml = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $vinList = [];
    foreach ($vehicles as $vehicle) {
        $vin = trim((string)($vehicle['vin'] ?? ''));
        if ($vin !== '') $vinList[] = $vin;
    }
    $vehicleCount = count($vehicles);
    $vins = $vinList ? implode(', ', $vinList) : 'No vehicle VINs were provided.';
    $bodyText = "Dear {$holderName},\n\nPlease find attached the Certificate of Insurance for {$insuredName}.\n\nVehicles included: {$vehicleCount} vehicle(s)\nVINs: {$vins}\n\nInsured: {$insuredName}\nAddress: {$insuredAddress}\n\nBest regards,\n{$contactName}\n{$companyName}\nPhone: {$contactPhone}\nEmail: {$contactEmail}\n";
    $html = '<!doctype html><html lang="en"><body style="margin:0;padding:24px 10px;background:#f2f5fa;font-family:Arial,Helvetica,sans-serif;color:#26364d;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e1e8f1;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="padding:25px 30px;background:#122747;color:#ffffff;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="width:46px;height:46px;border-radius:12px;background:#ffffff;color:#1d5fe8;text-align:center;vertical-align:middle;font-size:25px;font-weight:800;">A</td>'
        . '<td style="padding-left:12px;"><div style="font-size:19px;line-height:1.2;font-weight:800;letter-spacing:1px;">ACE INSURANCE</div><div style="margin-top:4px;color:#c6d5ea;font-size:10px;letter-spacing:2px;">CERTIFICATE SERVICES</div></td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="padding:30px;">'
        . '<div style="color:#1d5fe8;font-size:10px;font-weight:800;letter-spacing:1.6px;">CERTIFICATE OF INSURANCE</div>'
        . '<h1 style="margin:8px 0 18px;color:#172b49;font-size:23px;line-height:1.3;">Certificate attached</h1>'
        . '<p style="margin:0 0 16px;font-size:14px;line-height:1.7;">Dear <strong>' . $escapeHtml($holderName) . '</strong>,</p>'
        . '<p style="margin:0 0 21px;color:#52637a;font-size:14px;line-height:1.75;">Please find attached the Certificate of Insurance for <strong style="color:#1b2e4c;">' . $escapeHtml($insuredName) . '</strong>.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 21px;border:1px solid #e3eaf3;border-radius:10px;background:#f8fafd;">'
        . '<tr><td style="padding:16px 18px;">'
        . '<div style="margin-bottom:12px;color:#718198;font-size:10px;font-weight:800;letter-spacing:1.2px;">CERTIFICATE DETAILS</div>'
        . '<div style="margin-bottom:9px;font-size:13px;color:#293d5a;"><strong>Vehicles included:</strong> ' . $vehicleCount . ' vehicle(s)</div>'
        . '<div style="margin-bottom:9px;font-size:13px;color:#293d5a;line-height:1.65;"><strong>VINs:</strong> ' . $escapeHtml($vins) . '</div>'
        . '<div style="margin-bottom:9px;font-size:13px;color:#293d5a;"><strong>Insured:</strong> ' . $escapeHtml($insuredName) . '</div>'
        . '<div style="font-size:13px;color:#293d5a;line-height:1.65;"><strong>Address:</strong> ' . $escapeHtml($insuredAddress ?: 'Not provided') . '</div>'
        . '</td></tr></table>'
        . '<p style="margin:0 0 5px;color:#52637a;font-size:13px;">Best regards,</p>'
        . '<p style="margin:0;color:#172b49;font-size:14px;font-weight:800;">' . $escapeHtml($contactName) . '</p>'
        . '<p style="margin:4px 0 0;color:#52637a;font-size:12px;">' . $escapeHtml($companyName) . '</p>'
        . '<div style="margin-top:15px;padding-top:14px;border-top:1px solid #e8edf4;font-size:12px;line-height:1.9;color:#52637a;">'
        . '<div><strong style="color:#2b3d57;">Phone:</strong> <a href="tel:' . $escapeHtml($contactPhone) . '" style="color:#1d5fe8;text-decoration:none;">' . $escapeHtml($contactPhone) . '</a></div>'
        . '<div><strong style="color:#2b3d57;">Email:</strong> <a href="mailto:' . $escapeHtml($contactEmail) . '" style="color:#1d5fe8;text-decoration:none;">' . $escapeHtml($contactEmail) . '</a></div>'
        . '</div></td></tr>'
        . '<tr><td style="padding:15px 30px;background:#f8fafd;border-top:1px solid #e8edf4;color:#8794a7;font-size:10px;line-height:1.6;">This email was sent by ACE Insurance. The Certificate of Insurance is attached as a PDF.</td></tr>'
        . '</table></body></html>';

    $headers = [
        'From: ' . $fromName . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    if ($cc) $headers[] = 'Cc: ' . implode(', ', $cc);
    $body = '--' . $boundary . "\r\n";
    $body .= 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"' . "\r\n\r\n";
    $body .= '--' . $alternativeBoundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $bodyText . "\r\n";
    $body .= '--' . $alternativeBoundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html . "\r\n";
    $body .= '--' . $alternativeBoundary . "--\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: application/pdf; name="' . $filename . '"' . "\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n\r\n";
    $body .= chunk_split(base64_encode($pdf)) . "\r\n";
    $body .= '--' . $boundary . "--\r\n";
    return mail(implode(', ', $to), $encodedSubject, $body, implode("\r\n", $headers));
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
        'SELECT v.id, v.vin, v.unit_number, v.vehicle_type, v.make, v.model, v.model_year, v.value_amount, v.carrier_selections
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
            'SELECT id, vin, unit_number, vehicle_type, make, model, model_year, value_amount, carrier_selections
             FROM vehicles WHERE insured_id = :insured_id AND status = "active" ORDER BY id DESC'
        );
        $vehicleQ->execute(['insured_id' => $insuredId]);
        $vehicles = $vehicleQ->fetchAll();
    }

    $driverQ = db()->prepare(
        'SELECT d.id, d.full_name, d.license_number, d.license_state, d.is_excluded, d.carrier_selections
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
            'SELECT id, full_name, license_number, license_state, is_excluded, carrier_selections
             FROM drivers WHERE insured_id = :insured_id AND status = "active" ORDER BY full_name'
        );
        $driverQ->execute(['insured_id' => $insuredId]);
        $drivers = $driverQ->fetchAll();
    }

    // Resolve the saved policy-letter keys to company names only for customer-facing display.
    $carrierCompanyNamesByKey = [];
    foreach ($policies as $carrierPolicy) {
        foreach (range('A', 'F') as $letter) {
            $suffix = strtolower($letter);
            $companyName = trim((string)($carrierPolicy['insurer_' . $suffix . '_name'] ?? ''));
            if ($companyName !== '') {
                $carrierCompanyNamesByKey[(int)$carrierPolicy['id'] . ':' . $letter] = $companyName;
            }
        }
    }
    $attachCustomerCarrierNames = static function (array $records) use ($carrierCompanyNamesByKey): array {
        foreach ($records as &$record) {
            $rawSelections = $record['carrier_selections'] ?? [];
            if (is_string($rawSelections)) {
                $decodedSelections = json_decode($rawSelections, true);
                $rawSelections = is_array($decodedSelections) ? $decodedSelections : [];
            }
            $companyNames = [];
            if (is_array($rawSelections)) {
                foreach ($rawSelections as $selectionKey) {
                    if (is_string($selectionKey) && isset($carrierCompanyNamesByKey[$selectionKey])) {
                        $companyNames[] = $carrierCompanyNamesByKey[$selectionKey];
                    }
                }
            }
            $record['carrier_names'] = array_values(array_unique($companyNames));
        }
        unset($record);
        return $records;
    };
    $vehicles = $attachCustomerCarrierNames($vehicles);
    $drivers = $attachCustomerCarrierNames($drivers);

    $coverages = customerCoverageCards($selectedPolicy);
}

$policyInsurers = [];
if ($selectedPolicy) {
    $coverageForNaic = [];
    if (!empty($selectedPolicy['coverage_data'])) {
        $decoded = json_decode((string)$selectedPolicy['coverage_data'], true);
        if (is_array($decoded)) $coverageForNaic = $decoded;
    }
    foreach (range('A', 'F') as $letter) {
        $field = 'insurer_' . strtolower($letter);
        $name = trim((string)($selectedPolicy[$field . '_name'] ?? ''));
        $naic = trim((string)($selectedPolicy[$field . '_naic'] ?? ''));
        if ($letter === 'A') {
            if ($name === '') $name = trim((string)($selectedPolicy['carrier_name'] ?? ''));
            if ($naic === '') $naic = CertificatePdf::findCoverageValue($coverageForNaic, ['naic_number', 'insurer_naic', 'naic']);
        }
        if ($name !== '' || $naic !== '') $policyInsurers[$letter] = ['name' => $name, 'naic' => $naic];
    }
}


// Cab card PDF streaming is restricted to the authenticated customer's current policy/vehicle records.
if (isset($_GET['cab_card_pdf']) || isset($_GET['cab_cards_all'])) {
    if (!$selectedPolicy) {
        http_response_code(404);
        exit('No policy is available for this customer.');
    }
    if (!$vehicles) {
        http_response_code(404);
        exit('No active vehicles are available for this policy.');
    }

    $downloadCabCard = (string)($_GET['download'] ?? '') === '1';
    if (isset($_GET['cab_cards_all'])) {
        $cabCardVehicles = $vehicles;
    } else {
        $vehicleId = filter_var($_GET['cab_card_pdf'] ?? null, FILTER_VALIDATE_INT);
        if (!$vehicleId || $vehicleId < 1) {
            http_response_code(400);
            exit('Invalid vehicle ID.');
        }
        $cabCardVehicles = array_values(array_filter(
            $vehicles,
            static fn(array $vehicle): bool => (int)$vehicle['id'] === (int)$vehicleId
        ));
        if (!$cabCardVehicles) {
            http_response_code(404);
            exit('This vehicle is not available under the selected policy.');
        }
    }

    try {
        \AcePortal\CabCardPdf::stream(
            $cabCardVehicles,
            $selectedPolicy,
            $insured,
            configFile('mail'),
            $downloadCabCard
        );
    } catch (\Throwable $exception) {
        error_log('Customer cab card PDF failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('Cab card PDF could not be generated. Please contact your agency if the problem continues.');
    }
}



$certificateErrors = [];
$certificateForm = [
    'broker_emails' => '',
    'cc_emails' => '',
    'broker_name' => trim((string)($selectedPolicy['broker_name'] ?? '')),
    'broker_address' => '',
    'vehicle_ids' => array_map(static fn(array $v): string => (string)$v['id'], $vehicles),
];
$currentPolicyId = $selectedPolicy ? (int)$selectedPolicy['id'] : 0;
if ($currentPolicyId > 0 && isset($_SESSION['certificate_form'][$currentPolicyId]) && is_array($_SESSION['certificate_form'][$currentPolicyId])) {
    $certificateForm = array_merge($certificateForm, $_SESSION['certificate_form'][$currentPolicyId]);
}
// Remove deprecated holder inputs saved by older versions; broker details now fill the PDF holder area.
unset($certificateForm['certificate_holder_name'], $certificateForm['certificate_holder_address']);
$generatedId = null;
$requestedCertificateId = is_string($_GET['certificate_file'] ?? null) ? (string)$_GET['certificate_file'] : '';
if ($currentPolicyId > 0 && preg_match('/^[a-f0-9]{32}$/', $requestedCertificateId)) {
    $record = $_SESSION['certificate_files'][$requestedCertificateId] ?? null;
    if (is_array($record) && (int)($record['insured_id'] ?? 0) === $insuredId && (int)($record['policy_id'] ?? 0) === $currentPolicyId && is_file(APP_ROOT . '/storage/private/' . $requestedCertificateId . '.pdf')) {
        $generatedId = $requestedCertificateId;
    }
}

// Reuse the latest auto/manual preview for this policy while it remains in the
// authenticated customer's session. A new preview is created automatically below
// when the Certificate tab is opened and no usable preview exists.
if (!$generatedId && $currentPolicyId > 0) {
    $cachedPreviewId = (string)($_SESSION['certificate_preview_files'][$currentPolicyId] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $cachedPreviewId)) {
        $cachedPreview = $_SESSION['certificate_files'][$cachedPreviewId] ?? null;
        if (is_array($cachedPreview)
            && (int)($cachedPreview['insured_id'] ?? 0) === $insuredId
            && (int)($cachedPreview['policy_id'] ?? 0) === $currentPolicyId
            && is_file(APP_ROOT . '/storage/private/' . $cachedPreviewId . '.pdf')) {
            $generatedId = $cachedPreviewId;
        }
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

        if ($certificateForm['broker_name'] === '' || $certificateForm['broker_address'] === '') {
            throw new \RuntimeException('Enter the broker name and broker address. These details will appear as the certificate holder on the PDF.');
        }

        $toEmails = [];
        $ccEmails = [];
        if ($action === 'send') {
            $toEmails = certificateEmails($certificateForm['broker_emails']);
            $ccMap = [];
            foreach (array_merge(certificateEmails($certificateForm['cc_emails']), ['info@AceInsure.com']) as $address) $ccMap[strtolower($address)] = $address;
            $ccEmails = array_values($ccMap);
            if (!$toEmails) throw new \RuntimeException('Enter at least one broker email address.');
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
        $mailConfig = configFile('mail');
        $companySettings = db()->query('SELECT * FROM own_company_settings WHERE id = 1')->fetch() ?: [];
        $certificateConfig = array_merge($mailConfig, [
            'company_name' => (string)($companySettings['company_name'] ?? ''),
            'company_address' => (string)($companySettings['company_address'] ?? ''),
            'contact_person' => (string)($companySettings['contact_person'] ?? ''),
            'phone_number' => (string)($companySettings['phone_number'] ?? ''),
            'email_address' => (string)($companySettings['email_address'] ?? ''),
            'authorized_signature_name' => (string)($companySettings['authorized_signature_name'] ?? ''),
            'authorized_signature_file' => (string)($companySettings['authorized_signature_file'] ?? ''),
        ]);
        CertificatePdf::generate(
            $selectedPolicy,
            $insured,
            $selectedVehicles,
            $certificateForm,
            $certificateConfig,
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
        $_SESSION['certificate_preview_files'][$currentPolicyId] = $fileId;

        if ($action === 'send') {
            $insuredEmailAddress = implode(', ', array_filter([
                trim((string)($insured['address_line1'] ?? '')),
                trim((string)($insured['address_line2'] ?? '')),
                trim((string)($insured['city'] ?? '')),
                trim((string)($insured['state'] ?? '')),
                trim((string)($insured['postal_code'] ?? '')),
                trim((string)($insured['country'] ?? '')),
            ], static fn(string $part): bool => $part !== ''));
            $mailSent = sendCertificateMail(
                $toEmails,
                $ccEmails,
                (string)$selectedPolicy['policy_number'],
                (string)$insured['legal_name'],
                $insuredEmailAddress,
                $certificateForm['broker_name'],
                $selectedVehicles,
                $companySettings,
                $outputPath
            );
            if (!$mailSent) {
                throw new \RuntimeException('The PDF was generated, but the hosting mail service could not send it. Check config/mail.php and cPanel email delivery.');
            }
            audit('certificate_email_sent', 'policy', $currentPolicyId, 'Customer emailed certificate to broker for policy ' . (string)$selectedPolicy['policy_number']);
            $_SESSION['certificate_sent_modal'] = [
                'customer_name' => (string)$insured['legal_name'],
                'broker_emails' => implode(', ', $toEmails),
                'cc_emails' => implode(', ', $ccEmails),
                'policy_id' => $currentPolicyId,
            ];
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

// Automatically prepare a certificate draft the first time the customer opens
// the Certificate tab. This preview is generated from saved policy records and
// does not send or issue the certificate. Missing broker address stays blank
// for the customer to complete before sending.
if ($tab === 'certificate' && $_SERVER['REQUEST_METHOD'] === 'GET' && $selectedPolicy && $currentPolicyId > 0 && !$generatedId) {
    try {
        $allowedVehicleIds = array_map(static fn(array $v): int => (int)$v['id'], $vehicles);
        $requestedVehicleIds = array_map('intval', (array)($certificateForm['vehicle_ids'] ?? []));
        $selectedVehicleIds = array_values(array_intersect($requestedVehicleIds, $allowedVehicleIds));
        if (!$selectedVehicleIds) $selectedVehicleIds = $allowedVehicleIds;
        $selectedVehicles = array_values(array_filter(
            $vehicles,
            static fn(array $v): bool => in_array((int)$v['id'], $selectedVehicleIds, true)
        ));

        $fileId = bin2hex(random_bytes(16));
        $storageDirectory = APP_ROOT . '/storage/private';
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
            throw new RuntimeException('Could not create private certificate storage. Check folder permissions.');
        }
        $outputPath = $storageDirectory . '/' . $fileId . '.pdf';
        $mailConfig = configFile('mail');
        $companySettings = db()->query('SELECT * FROM own_company_settings WHERE id = 1')->fetch() ?: [];
        $certificateConfig = array_merge($mailConfig, [
            'company_name' => (string)($companySettings['company_name'] ?? ''),
            'company_address' => (string)($companySettings['company_address'] ?? ''),
            'contact_person' => (string)($companySettings['contact_person'] ?? ''),
            'phone_number' => (string)($companySettings['phone_number'] ?? ''),
            'email_address' => (string)($companySettings['email_address'] ?? ''),
            'authorized_signature_name' => (string)($companySettings['authorized_signature_name'] ?? ''),
            'authorized_signature_file' => (string)($companySettings['authorized_signature_file'] ?? ''),
        ]);
        CertificatePdf::generate(
            $selectedPolicy,
            $insured,
            $selectedVehicles,
            $certificateForm,
            $certificateConfig,
            $outputPath
        );
        $_SESSION['certificate_files'][$fileId] = [
            'insured_id' => $insuredId,
            'policy_id' => $currentPolicyId,
            'created_at' => time(),
        ];
        $_SESSION['certificate_preview_files'][$currentPolicyId] = $fileId;
        if (count($_SESSION['certificate_files']) > 12) {
            $_SESSION['certificate_files'] = array_slice($_SESSION['certificate_files'], -12, null, true);
        }
        $generatedId = $fileId;
    } catch (Throwable $exception) {
        error_log('Customer automatic certificate preview failed: ' . $exception->getMessage());
        $certificateErrors[] = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Certificate preview could not be generated automatically. Please try again or contact your agency.';
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
$certificateSentModal = $_SESSION['certificate_sent_modal'] ?? null;
unset($_SESSION['certificate_sent_modal']);
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
                        <?php if ($policyInsurers): foreach ($policyInsurers as $letter => $insurer): ?>
                        <div><dt>Insurer <?= customerText($letter) ?></dt><dd><?= customerText($insurer['name'] ?: '—') ?><?= $insurer['naic'] !== '' ? ' · NAIC ' . customerText($insurer['naic']) : '' ?></dd></div>
                        <?php endforeach; else: ?><div><dt>Insurance Company</dt><dd>Not provided</dd></div><?php endif; ?>
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
                    <div class="table-wrap"><table><thead><tr><th>VIN</th><th>Make</th><th>Model</th><th>Year</th><th>Unit</th><th>Value</th><th>Insurance Carrier</th></tr></thead><tbody>
                    <?php foreach ($vehicles as $vehicle): ?><tr><td class="mono"><?= customerText($vehicle['vin']) ?></td><td><?= customerText($vehicle['make'] ?: '—') ?></td><td><?= customerText($vehicle['model'] ?: '—') ?></td><td><?= customerText($vehicle['model_year'] ?: '—') ?></td><td><?= customerText($vehicle['unit_number'] ?: '—') ?></td><td><?= customerMoney($vehicle['value_amount']) ?></td><td><?= customerText($vehicle['carrier_names'] ? implode(', ', $vehicle['carrier_names']) : '—') ?></td></tr><?php endforeach; ?>
                    <?php if (!$vehicles): ?><tr><td colspan="7" class="empty-cell">No vehicles have been added to this insured account.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

                <section class="panel policy-table-panel"><div class="section-heading"><h2>Drivers <span class="count-pill"><?= count($drivers) ?></span></h2><span class="muted small">Drivers on file</span></div>
                    <div class="table-wrap"><table><thead><tr><th>Name</th><th>License</th><th>State</th><th>Excluded</th><th>Insurance Carrier</th></tr></thead><tbody>
                    <?php foreach ($drivers as $driver): ?><tr><td><?= customerText($driver['full_name']) ?></td><td><?= customerText($driver['license_number'] ?: '—') ?></td><td><?= customerText($driver['license_state'] ?: '—') ?></td><td><span class="status-pill <?= empty($driver['is_excluded']) ? 'is-active' : 'is-inactive' ?>"><?= empty($driver['is_excluded']) ? 'No' : 'Yes' ?></span></td><td><?= customerText($driver['carrier_names'] ? implode(', ', $driver['carrier_names']) : '—') ?></td></tr><?php endforeach; ?>
                    <?php if (!$drivers): ?><tr><td colspan="5" class="empty-cell">No drivers have been added to this insured account.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            </div>

            <section class="panel customer-coverages-panel">
                <div class="section-heading customer-coverages-heading">
                    <div><h2>Insurance Coverages</h2><p class="muted small">Your insurance plans, shown separately with policy dates and key limits.</p></div>
                    <?php if ($coverages): ?><span class="count-pill"><?= count($coverages) ?> <?= count($coverages) === 1 ? 'plan' : 'plans' ?></span><?php endif; ?>
                </div>
                <?php if ($coverages): ?>
                    <div class="customer-coverage-grid">
                        <?php foreach ($coverages as $coverage): ?>
                            <article class="customer-coverage-card">
                                <div class="customer-coverage-card-head">
                                    <div class="customer-coverage-title-wrap">
                                        <span class="customer-coverage-symbol" aria-hidden="true"><?= customerText($coverage['symbol']) ?></span>
                                        <div class="customer-coverage-title">
                                            <h3><?= customerText($coverage['name']) ?></h3>
                                            <span><?= $coverage['policy_number'] !== '' ? 'Policy #'.customerText($coverage['policy_number']) : 'Policy number not added' ?></span>
                                            <?php if ($coverage['options']): ?>
                                                <div class="customer-coverage-options customer-coverage-options-top">
                                                    <div><?php foreach ($coverage['options'] as $option): ?><span><?= customerText($option) ?></span><?php endforeach; ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="customer-coverage-status <?= customerText($coverage['status_class']) ?>"><?= customerText($coverage['status']) ?></span>
                                </div>
                                <div class="customer-coverage-period">
                                    <div><span>POLICY STARTS</span><strong><?= $coverage['start_date'] !== '' ? customerDate($coverage['start_date']) : 'Not provided' ?></strong></div>
                                    <span class="customer-coverage-period-arrow" aria-hidden="true">→</span>
                                    <div><span>POLICY ENDS</span><strong><?= $coverage['end_date'] !== '' ? customerDate($coverage['end_date']) : 'Not provided' ?></strong></div>
                                </div>
                                <?php if ($coverage['carrier'] !== ''): ?><div class="customer-coverage-carrier"><span>Insurance carrier</span><strong><?= customerText($coverage['carrier']) ?></strong></div><?php endif; ?>
                                <?php if ($coverage['highlights']): ?>
                                    <div class="customer-coverage-highlights">
                                        <?php foreach ($coverage['highlights'] as [$highlightLabel, $highlightValue]): ?>
                                            <div><span><?= customerText($highlightLabel) ?></span><strong><?= customerText($highlightValue) ?></strong></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($coverage['details'] || $coverage['description'] !== ''): ?>
                                    <details class="customer-coverage-more">
                                        <summary>See all limits and details</summary>
                                        <?php if ($coverage['details']): ?><div class="customer-coverage-detail-list"><?php foreach ($coverage['details'] as [$detailLabel, $detailValue]): ?><div><span><?= customerText($detailLabel) ?></span><strong><?= customerText($detailValue) ?></strong></div><?php endforeach; ?></div><?php endif; ?>
                                        <?php if ($coverage['description'] !== ''): ?><p class="customer-coverage-description"><?= customerText($coverage['description']) ?></p><?php endif; ?>
                                    </details>
                                <?php elseif ($coverage['status_class'] === 'is-incomplete'): ?>
                                    <p class="customer-coverage-note">Your agency has not added a separate policy period for this coverage yet. Please contact your agency for confirmation.</p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="customer-coverage-empty"><span aria-hidden="true">▤</span><h3>No insurance coverages added</h3><p>Your agency has not selected insurance types for this policy yet.</p></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    <?php elseif ($tab === 'certificate'): ?>
        <?php if (!$policies || !$selectedPolicy): ?>
            <section class="panel customer-placeholder"><div class="empty-icon">▧</div><h2>No policy available</h2><p class="muted">A certificate can be generated after your agency adds a policy to your account.</p></section>
        <?php else: ?>
            <div class="certificate-topline">
                <div><p class="eyebrow">CERTIFICATE OF INSURANCE</p><h2><?= customerText($selectedPolicy['policy_number']) ?></h2><p class="muted"><?= customerText($selectedPolicy['insurer_a_name'] ?: ($selectedPolicy['carrier_name'] ?: 'Insurance company not provided')) ?> · <?= customerDate($selectedPolicy['effective_date']) ?> – <?= customerDate($selectedPolicy['expiration_date']) ?></p></div>
                <?php if (count($policies) > 1): ?><form method="get" class="policy-select-form"><input type="hidden" name="tab" value="certificate"><label for="certificate-policy">Policy</label><select id="certificate-policy" name="policy_id" onchange="this.form.submit()"><?php foreach ($policies as $policyOption): ?><option value="<?= (int)$policyOption['id'] ?>" <?= (int)$policyOption['id'] === (int)$selectedPolicy['id'] ? 'selected' : '' ?>><?= customerText($policyOption['policy_number']) ?> · <?= customerText($policyOption['business_line'] ?? 'Policy') ?></option><?php endforeach; ?></select></form><?php endif; ?>
            </div>
            <div class="certificate-layout">
                <section class="panel certificate-form-panel">
                    <div class="panel-heading"><h2>Send to Broker</h2><span>Policy data is read-only</span></div>
                    <?php foreach ($certificateErrors as $certificateError): ?><div class="alert error certificate-error"><?= customerText($certificateError) ?></div><?php endforeach; ?>
                    <form method="post" action="<?= customerText(appUrl('/customer/index.php?tab=certificate&policy_id='.(int)$selectedPolicy['id'])) ?>" class="certificate-form">
                        <input type="hidden" name="_csrf" value="<?= customerText(csrfToken()) ?>">
                        <input type="hidden" name="policy_id" value="<?= (int)$selectedPolicy['id'] ?>">
                        <div class="cert-form-section">EMAIL DELIVERY</div>
                        <label>Broker Email(s)<input type="text" name="broker_emails" value="<?= customerText((string)$certificateForm['broker_emails']) ?>" placeholder="broker@example.com, broker2@example.com"><small>Required only when sending. Separate multiple email addresses with commas.</small></label>
                        <label>Additional CC Email(s)<input type="text" name="cc_emails" value="<?= customerText((string)$certificateForm['cc_emails']) ?>" placeholder="additional@example.com"><small>Optional. <strong>info@AceInsure.com</strong> is automatically copied on every certificate email.</small></label>
                        <div class="cert-form-section">BROKER / CERTIFICATE HOLDER</div>
                        <label>Broker Name *<input type="text" name="broker_name" value="<?= customerText((string)$certificateForm['broker_name']) ?>" required maxlength="190" placeholder="Certificate holder name"></label>
                        <label>Broker Address *<textarea name="broker_address" rows="3" maxlength="700" required placeholder="Certificate holder address"><?= customerText((string)$certificateForm['broker_address']) ?></textarea><small>This broker name and address will be placed in the Certificate Holder section of the PDF.</small></label>
                        <div class="cert-form-section">VEHICLES TO INCLUDE</div>
                        <label>Vehicles<select name="vehicle_ids[]" multiple size="4" class="vehicle-multiselect"><?php foreach ($vehicles as $vehicleOption): ?><option value="<?= (int)$vehicleOption['id'] ?>" <?= in_array((string)$vehicleOption['id'], array_map('strval', (array)$certificateForm['vehicle_ids']), true) ? 'selected' : '' ?>><?= customerText($vehicleOption['vin']) ?> (<?= customerText(trim(($vehicleOption['model_year'] ?? '').' '.($vehicleOption['make'] ?? '').' '.($vehicleOption['model'] ?? ''))) ?>)</option><?php endforeach; ?></select><small>Use Ctrl (Windows) or Command (Mac) to select multiple vehicles. If none is selected, all available vehicles are included.</small></label>
                        <div class="certificate-form-actions"><button class="btn primary" type="submit" name="certificate_action" value="send">➤ Send to Broker</button><button class="btn secondary" type="submit" name="certificate_action" value="preview">▣ Generate PDF Preview</button></div>
                        <p class="certificate-small-note">Missing policy limits or insurer identifiers stay blank when they are not present in the database.</p>
                    </form>
                </section>
                <section class="panel certificate-preview-panel" id="certificate-preview">
                    <div class="panel-heading"><h2>PDF Preview</h2><span><?= $generatedId ? 'Auto-generated draft' : 'Preview will appear here' ?></span></div>
                    <div class="certificate-preview-inner">
                        <div class="preview-status"><span class="status-pill <?= $generatedId ? 'is-active' : '' ?>"><?= $generatedId ? '● Preview ready' : 'Waiting for preview' ?></span><span class="muted small">ACORD 25 · US Letter</span></div>
                        <?php if ($generatedId): ?><p class="certificate-small-note">This draft is prepared automatically from your saved policy. Review the broker name and address before sending it.</p><?php endif; ?>
                        <?php if ($generatedId): ?>
                            <iframe id="certificatePreviewFrame" class="certificate-pdf-frame" title="Certificate of Insurance PDF preview" src="<?= customerText(appUrl('/customer/index.php?file='.$generatedId)) ?>#toolbar=0&navpanes=0&scrollbar=0&view=FitH"></iframe>
                            <div class="certificate-preview-actions"><a class="btn primary" href="<?= customerText(appUrl('/customer/index.php?file='.$generatedId.'&download=1')) ?>">↓ Download PDF</a><a class="btn secondary" href="<?= customerText(appUrl('/customer/index.php?file='.$generatedId)) ?>" target="_blank" rel="noopener">⎙ Print Certificate</a></div>
                        <?php else: ?>
                            <div class="certificate-preview-empty"><div class="empty-icon">▧</div><h3>Certificate preview</h3><p class="muted">Enter the certificate holder information, then select Generate PDF Preview. Policy number, carrier and dates will be taken from your saved policy.</p></div>
                        <?php endif; ?>
                    </div>
                    <div class="certificate-footnote">Technical PDF generation only. The issuing agency must review and authorize certificate content before it is relied upon as evidence of coverage.</div>
                </section>
            </div>
        <?php endif; ?>
    <?php elseif ($tab === 'cab-card'): ?>
        <?php if (!$policies || !$selectedPolicy): ?>
            <section class="panel customer-placeholder"><div class="empty-icon">▣</div><h2>No policy available</h2><p class="muted">Your agency needs to add a policy before cab cards can be generated.</p><a class="btn primary" href="<?= customerText(appUrl('/customer/index.php?tab=policies')) ?>">View Policies</a></section>
        <?php else: ?>
            <div class="cab-card-toolbar">
                <div><p class="eyebrow">INSURANCE IDENTIFICATION CARDS</p><h2>Cab Cards <span class="count-pill"><?= count($vehicles) ?></span></h2><p class="muted">Policy <?= customerText($selectedPolicy['policy_number']) ?> · <?= customerDate($selectedPolicy['effective_date']) ?> – <?= customerDate($selectedPolicy['expiration_date']) ?></p></div>
                <?php if ($vehicles): ?><a class="btn primary cab-card-download-all" href="<?= customerText(appUrl('/customer/index.php?tab=cab-card&policy_id='.(int)$selectedPolicy['id'].'&cab_cards_all=1&download=1')) ?>">↓ Download All Cab Cards</a><?php endif; ?>
            </div>

            <?php if (count($policies) > 1): ?>
                <form method="get" class="cab-card-policy-select"><input type="hidden" name="tab" value="cab-card"><label for="cab-card-policy">Select policy</label><select id="cab-card-policy" name="policy_id" onchange="this.form.submit()"><?php foreach ($policies as $policyOption): ?><option value="<?= (int)$policyOption['id'] ?>" <?= (int)$policyOption['id'] === (int)$selectedPolicy['id'] ? 'selected' : '' ?>><?= customerText($policyOption['policy_number']) ?> · <?= customerText($policyOption['business_line'] ?? 'Policy') ?></option><?php endforeach; ?></select></form>
            <?php endif; ?>

            <?php if (!$vehicles): ?>
                <section class="panel customer-empty"><div class="empty-icon">▣</div><h2>No active vehicles found</h2><p class="muted">There are no active vehicles associated with this policy yet. Please contact your agency or check the Vehicles section.</p><a class="btn secondary" href="<?= customerText(appUrl('/customer/index.php?tab=policies&policy_id='.(int)$selectedPolicy['id'])) ?>">View Policy Details</a></section>
            <?php else: ?>
                <div class="cab-card-grid">
                    <?php foreach ($vehicles as $vehicleIndex => $vehicle): ?>
                        <?php
                            $cabType = strtoupper(trim((string)($vehicle['vehicle_type'] ?? '')));
                            if ($cabType === '') $cabType = 'TRUCK';
                            $cabMakeModel = trim((string)($vehicle['make'] ?? '') . ' ' . (string)($vehicle['model'] ?? ''));
                            $cabBaseUrl = '/customer/index.php?tab=cab-card&policy_id='.(int)$selectedPolicy['id'].'&cab_card_pdf='.(int)$vehicle['id'];
                        ?>
                        <article class="cab-card-item">
                            <div class="cab-card-item-header"><span><span class="cab-truck-icon">▣</span> <?= customerText($cabType) ?></span><span class="cab-card-number">#<?= (int)$vehicleIndex + 1 ?></span></div>
                            <div class="cab-card-item-body">
                                <div class="cab-detail full"><span>VIN</span><strong class="cab-vin"><?= customerText($vehicle['vin']) ?></strong></div>
                                <div class="cab-vehicle-meta">
                                    <div><span>Make/Model</span><strong><?= customerText($cabMakeModel ?: '—') ?></strong></div>
                                    <div><span>Year</span><strong><?= customerText($vehicle['model_year'] ?? '—') ?></strong></div>
                                </div>
                                <div class="cab-detail"><span>Policy</span><strong><?= customerText($selectedPolicy['policy_number']) ?></strong></div>
                                <div class="cab-card-actions">
                                    <a class="btn secondary" href="<?= customerText(appUrl($cabBaseUrl).'#toolbar=0&navpanes=0&scrollbar=0&view=FitH') ?>" target="_blank" rel="noopener">◉ View Cab Card</a>
                                    <a class="btn cab-print" href="<?= customerText(appUrl($cabBaseUrl).'#toolbar=0&navpanes=0&scrollbar=0&view=FitH') ?>" target="_blank" rel="noopener">▣ Print Card</a>
                                    <a class="btn cab-download" href="<?= customerText(appUrl($cabBaseUrl.'&download=1')) ?>">↓ Download PDF</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <p class="cab-card-note">Cab cards are generated from current policy and vehicle records. Verify the displayed information with your issuing agency before relying on the card as proof of insurance.</p>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if (is_array($certificateSentModal)): ?>
<div class="certificate-success-modal" role="dialog" aria-modal="true" aria-labelledby="certificate-success-title">
    <div class="certificate-success-backdrop"></div>
    <section class="certificate-success-dialog">
        <div class="certificate-success-brand">
            <span class="certificate-success-logo">A</span>
            <span><strong>ACE INSURANCE</strong><small>CERTIFICATE SERVICES</small></span>
        </div>
        <div class="certificate-success-check" aria-hidden="true">✓</div>
        <p class="certificate-success-eyebrow">EMAIL DELIVERY COMPLETE</p>
        <h2 id="certificate-success-title">Certificate Sent Successfully!</h2>
        <p class="certificate-success-copy">The Certificate of Insurance for <strong><?= customerText((string)($certificateSentModal['customer_name'] ?? '')) ?></strong> has been sent with the PDF attached.</p>
        <div class="certificate-success-meta">
            <div><span>Sent to</span><strong><?= customerText((string)($certificateSentModal['broker_emails'] ?? '')) ?></strong></div>
            <div><span>CC</span><strong><?= customerText((string)($certificateSentModal['cc_emails'] ?? 'info@AceInsure.com')) ?></strong></div>
            <div><span>Attachment</span><strong>Certificate of Insurance · PDF</strong></div>
        </div>
        <a class="btn primary certificate-success-done" href="<?= customerText(appUrl('/customer/index.php?tab=policies&policy_id='.(int)($certificateSentModal['policy_id'] ?? 0))) ?>">Done</a>
        <p class="certificate-success-footnote">You can view your policy details anytime from the Policies section.</p>
    </section>
</div>
<?php endif; ?>
</body>
</html>
