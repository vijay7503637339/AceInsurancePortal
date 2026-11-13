<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';

use AcePortal\Auth;
use AcePortal\Crud;

$u = Auth::requireRoles(['admin','staff']);
$error = null;
$flash = getFlash();
$form = $_POST ?: [];
$insuredIdRaw = filter_var($_GET['insured_id'] ?? $form['insured_id'] ?? null, FILTER_VALIDATE_INT);
$insuredId = ($insuredIdRaw && $insuredIdRaw > 0) ? (int)$insuredIdRaw : 0;

$customers = db()->query("SELECT id, legal_name, dot_number, email, status FROM insureds WHERE status <> 'inactive' ORDER BY legal_name")->fetchAll();
$customer = null;
$policies = [];
$drivers = [];
$vehicles = [];
$selectedPolicy = null;

if ($insuredId > 0) {
    $q = db()->prepare('SELECT * FROM insureds WHERE id = :id LIMIT 1');
    $q->execute(['id' => $insuredId]);
    $customer = $q->fetch() ?: null;
    if (!$customer) {
        $insuredId = 0;
    } else {
        $q = db()->prepare("SELECT id, policy_number, business_line, status, effective_date, expiration_date, total_premium FROM policies WHERE insured_id = :insured_id ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id DESC");
        $q->execute(['insured_id' => $insuredId]);
        $policies = $q->fetchAll();

        $q = db()->prepare("SELECT id, full_name, license_number, license_state, date_of_birth, is_excluded FROM drivers WHERE insured_id = :insured_id AND status = 'active' ORDER BY full_name");
        $q->execute(['insured_id' => $insuredId]);
        $drivers = $q->fetchAll();

        $q = db()->prepare("SELECT id, vin, unit_number, vehicle_type, make, model, model_year, value_amount FROM vehicles WHERE insured_id = :insured_id AND status = 'active' ORDER BY id DESC");
        $q->execute(['insured_id' => $insuredId]);
        $vehicles = $q->fetchAll();

        $policyIdRaw = filter_var($_GET['policy_id'] ?? $form['policy_id'] ?? null, FILTER_VALIDATE_INT);
        $policyId = ($policyIdRaw && $policyIdRaw > 0) ? (int)$policyIdRaw : 0;
        foreach ($policies as $row) {
            if ((int)$row['id'] === $policyId) {
                $selectedPolicy = $row;
                break;
            }
        }
        if (!$selectedPolicy && $policies) $selectedPolicy = $policies[0];
    }
}

$coverageTypes = [
    'commercial_general_liability' => 'Commercial General Liability',
    'automobile_liability' => 'Automobile Liability',
    'physical_damage' => 'Physical Damage',
    'motor_truck_cargo' => 'Motor Truck Cargo',
    'umbrella_liability' => 'Umbrella Liability',
    'excess_liability' => 'Excess Liability',
    'workers_compensation_employers_liability' => "Workers Compensation & Employers' Liability"
];

function endorsementAmount($raw, string $label): string {
    $value = trim((string)$raw);
    $normalized = preg_replace('/[,$\s]/', '', $value) ?? '';
    if ($normalized === '' || !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $normalized)) {
        throw new InvalidArgumentException($label.' must be a valid amount (up to 12 digits and 2 decimals).');
    }
    return $normalized;
}

function endorsementDate($raw, string $label): ?string {
    $value = trim((string)$raw);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label.' must be a valid date.');
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if (!$customer) throw new InvalidArgumentException('Select a valid customer first.');
        $policyId = filter_var($_POST['policy_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$policyId || $policyId < 1) throw new InvalidArgumentException('Select the policy this endorsement applies to.');

        $q = db()->prepare('SELECT * FROM policies WHERE id = :id AND insured_id = :insured_id LIMIT 1');
        $q->execute(['id' => (int)$policyId, 'insured_id' => $insuredId]);
        $policy = $q->fetch();
        if (!$policy) throw new InvalidArgumentException('The selected policy does not belong to this customer.');

        $category = trim((string)($_POST['category'] ?? ''));
        $actionType = trim((string)($_POST['action_type'] ?? ''));
        $summary = '';
        $subjectType = $category;
        $subjectId = null;
        $before = null;
        $after = null;
        $detail = [];
        $pdo = db();

        $pdo->beginTransaction();
        try {
            if ($category === 'driver') {
                if ($actionType === 'add') {
                    $name = trim((string)($_POST['driver_full_name'] ?? ''));
                    $license = trim((string)($_POST['driver_license_number'] ?? ''));
                    $state = trim((string)($_POST['driver_license_state'] ?? ''));
                    $dob = endorsementDate($_POST['driver_date_of_birth'] ?? '', 'Driver date of birth');
                    $excluded = (string)($_POST['driver_is_excluded'] ?? '0');
                    if ($name === '') throw new InvalidArgumentException('Enter the new driver name.');
                    if (!in_array($excluded, ['0','1'], true)) throw new InvalidArgumentException('Select whether the driver is excluded.');
                    if ($state !== '' && !in_array($state, Crud::US_LICENSE_STATES, true)) throw new InvalidArgumentException('Select a valid U.S. license state.');

                    $q = $pdo->prepare("INSERT INTO drivers (insured_id, full_name, license_number, license_state, date_of_birth, is_excluded, status, created_by) VALUES (:insured_id, :full_name, :license_number, :license_state, :date_of_birth, :is_excluded, 'active', :created_by)");
                    $q->execute([
                        'insured_id' => $insuredId,
                        'full_name' => $name,
                        'license_number' => $license !== '' ? $license : null,
                        'license_state' => $state !== '' ? $state : null,
                        'date_of_birth' => $dob,
                        'is_excluded' => (int)$excluded,
                        'created_by' => (int)$u['id']
                    ]);
                    $subjectId = (int)$pdo->lastInsertId();
                    $activeDrivers = $pdo->prepare("SELECT id FROM drivers WHERE insured_id = :insured_id AND status = 'active'");
                    $activeDrivers->execute(['insured_id' => $insuredId]);
                    $linkDriver = $pdo->prepare('INSERT IGNORE INTO policy_drivers (policy_id, driver_id) VALUES (:policy_id, :driver_id)');
                    foreach ($activeDrivers->fetchAll(PDO::FETCH_COLUMN) as $activeDriverId) {
                        $linkDriver->execute(['policy_id' => (int)$policyId, 'driver_id' => (int)$activeDriverId]);
                    }
                    $q = $pdo->prepare('SELECT * FROM drivers WHERE id = :id');
                    $q->execute(['id' => $subjectId]);
                    $after = $q->fetch();
                    $summary = 'Added driver '.$name.' to the selected customer and policy.';
                    $detail = ['driver' => $after];
                } elseif ($actionType === 'remove') {
                    $subjectId = filter_var($_POST['driver_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$subjectId || $subjectId < 1) throw new InvalidArgumentException('Select a driver to remove.');
                    $q = $pdo->prepare("SELECT * FROM drivers WHERE id = :id AND insured_id = :insured_id AND status = 'active' FOR UPDATE");
                    $q->execute(['id' => (int)$subjectId, 'insured_id' => $insuredId]);
                    $before = $q->fetch();
                    if (!$before) throw new InvalidArgumentException('The selected driver is no longer active for this customer.');
                    $pdo->prepare("UPDATE drivers SET status = 'inactive' WHERE id = :id AND insured_id = :insured_id")
                        ->execute(['id' => (int)$subjectId, 'insured_id' => $insuredId]);
                    $after = $before;
                    $after['status'] = 'inactive';
                    $summary = 'Removed driver '.$before['full_name'].' from the active roster. The record was archived, not deleted.';
                    $detail = ['driver_before' => $before, 'driver_after' => $after, 'record_action' => 'archived'];
                } else {
                    throw new InvalidArgumentException('Choose Add Driver or Remove Driver.');
                }
            } elseif ($category === 'vehicle') {
                if ($actionType === 'add') {
                    $vin = strtoupper(trim((string)($_POST['vehicle_vin'] ?? '')));
                    $unit = trim((string)($_POST['vehicle_unit_number'] ?? ''));
                    $vehicleType = trim((string)($_POST['vehicle_type'] ?? ''));
                    $make = trim((string)($_POST['vehicle_make'] ?? ''));
                    $model = trim((string)($_POST['vehicle_model'] ?? ''));
                    $year = trim((string)($_POST['vehicle_model_year'] ?? ''));
                    $rawValue = trim((string)($_POST['vehicle_value_amount'] ?? ''));
                    $value = $rawValue !== '' ? endorsementAmount($rawValue, 'Vehicle value') : null;
                    if ($vin === '') throw new InvalidArgumentException('Enter the vehicle VIN.');
                    if ($year !== '' && (!ctype_digit($year) || (int)$year < 1900 || (int)$year > 2100)) throw new InvalidArgumentException('Enter a valid vehicle model year.');

                    $q = $pdo->prepare("INSERT INTO vehicles (insured_id, vin, unit_number, vehicle_type, make, model, model_year, value_amount, status, created_by) VALUES (:insured_id, :vin, :unit_number, :vehicle_type, :make, :model, :model_year, :value_amount, 'active', :created_by)");
                    $q->execute([
                        'insured_id' => $insuredId,
                        'vin' => $vin,
                        'unit_number' => $unit !== '' ? $unit : null,
                        'vehicle_type' => $vehicleType !== '' ? $vehicleType : null,
                        'make' => $make !== '' ? $make : null,
                        'model' => $model !== '' ? $model : null,
                        'model_year' => $year !== '' ? (int)$year : null,
                        'value_amount' => $value,
                        'created_by' => (int)$u['id']
                    ]);
                    $subjectId = (int)$pdo->lastInsertId();
                    $activeVehicles = $pdo->prepare("SELECT id FROM vehicles WHERE insured_id = :insured_id AND status = 'active'");
                    $activeVehicles->execute(['insured_id' => $insuredId]);
                    $linkVehicle = $pdo->prepare('INSERT IGNORE INTO policy_vehicles (policy_id, vehicle_id) VALUES (:policy_id, :vehicle_id)');
                    foreach ($activeVehicles->fetchAll(PDO::FETCH_COLUMN) as $activeVehicleId) {
                        $linkVehicle->execute(['policy_id' => (int)$policyId, 'vehicle_id' => (int)$activeVehicleId]);
                    }
                    $q = $pdo->prepare('SELECT * FROM vehicles WHERE id = :id');
                    $q->execute(['id' => $subjectId]);
                    $after = $q->fetch();
                    $summary = 'Added vehicle '.$vin.' to the selected customer and policy.';
                    $detail = ['vehicle' => $after];
                } elseif ($actionType === 'remove' || $actionType === 'value_update') {
                    $subjectId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$subjectId || $subjectId < 1) throw new InvalidArgumentException('Select a vehicle.');
                    $q = $pdo->prepare("SELECT * FROM vehicles WHERE id = :id AND insured_id = :insured_id AND status = 'active' FOR UPDATE");
                    $q->execute(['id' => (int)$subjectId, 'insured_id' => $insuredId]);
                    $before = $q->fetch();
                    if (!$before) throw new InvalidArgumentException('The selected vehicle is no longer active for this customer.');
                    if ($actionType === 'remove') {
                        $pdo->prepare("UPDATE vehicles SET status = 'inactive' WHERE id = :id AND insured_id = :insured_id")
                            ->execute(['id' => (int)$subjectId, 'insured_id' => $insuredId]);
                        $after = $before;
                        $after['status'] = 'inactive';
                        $summary = 'Removed vehicle '.$before['vin'].' from the active roster. The record was archived, not deleted.';
                        $detail = ['vehicle_before' => $before, 'vehicle_after' => $after, 'record_action' => 'archived'];
                    } else {
                        $newValue = endorsementAmount($_POST['vehicle_new_value'] ?? '', 'Vehicle value');
                        $pdo->prepare('UPDATE vehicles SET value_amount = :value WHERE id = :id AND insured_id = :insured_id')
                            ->execute(['value' => $newValue, 'id' => (int)$subjectId, 'insured_id' => $insuredId]);
                        $after = $before;
                        $after['value_amount'] = $newValue;
                        $summary = 'Updated vehicle '.$before['vin'].' value from '.(string)($before['value_amount'] ?? 'not set').' to '.$newValue.'.';
                        $detail = ['vehicle_before' => $before, 'vehicle_after' => $after, 'field' => 'value_amount'];
                    }
                } else {
                    throw new InvalidArgumentException('Choose Add, Remove or Update Vehicle Value.');
                }
            } elseif ($category === 'policy') {
                $coverageData = [];
                if (!empty($policy['coverage_data'])) {
                    $decoded = json_decode((string)$policy['coverage_data'], true);
                    if (is_array($decoded)) $coverageData = $decoded;
                }
                $coverageDataBefore = $coverageData;

                if ($actionType === 'amount_update') {
                    $amountLabels = [
                        'written_premium'=>'Written Premium',
                        'estimated_fee'=>'Estimated Fee',
                        'estimated_tax'=>'Estimated Tax',
                        'total_premium'=>'Total Premium'
                    ];
                    $field = (string)($_POST['policy_amount_field'] ?? '');
                    if (!isset($amountLabels[$field])) throw new InvalidArgumentException('Select a valid policy amount.');
                    $newValue = endorsementAmount($_POST['policy_new_amount'] ?? '', $amountLabels[$field]);
                    $before = ['field'=>$field,'label'=>$amountLabels[$field],'value'=>$policy[$field]];
                    $pdo->prepare('UPDATE policies SET '.$field.' = :value WHERE id = :id AND insured_id = :insured_id')
                        ->execute(['value'=>$newValue,'id'=>(int)$policyId,'insured_id'=>$insuredId]);
                    $after = ['field'=>$field,'label'=>$amountLabels[$field],'value'=>$newValue];
                    $summary = 'Updated '.$amountLabels[$field].' on policy '.$policy['policy_number'].' from '.(string)($before['value'] ?? 'not set').' to '.$newValue.'.';
                    $subjectType = 'policy';
                    $subjectId = (int)$policyId;
                    $detail = ['before'=>$before,'after'=>$after];
                } elseif ($actionType === 'coverage_add' || $actionType === 'coverage_remove') {
                    $coverageKey = (string)($_POST['coverage_type'] ?? '');
                    if (!isset($coverageTypes[$coverageKey])) throw new InvalidArgumentException('Select a valid coverage type.');
                    $types = is_array($coverageData['insurance_types'] ?? null) ? array_values(array_map('strval',$coverageData['insurance_types'])) : [];
                    $exists = in_array($coverageKey,$types,true);
                    if ($actionType === 'coverage_add' && $exists) throw new InvalidArgumentException('This coverage is already included in the policy.');
                    if ($actionType === 'coverage_remove' && !$exists) throw new InvalidArgumentException('This coverage is not currently selected on the policy.');
                    $before = ['insurance_types'=>$types,'coverage'=>$coverageData[$coverageKey] ?? null,'insurance_carrier'=>$coverageData['insurance_carriers'][$coverageKey] ?? null,'coverage_data_snapshot'=>$coverageDataBefore];
                    if ($actionType === 'coverage_add') {
                        $types[] = $coverageKey;
                        if (!is_array($coverageData['insurance_carriers'] ?? null)) $coverageData['insurance_carriers'] = [];
                        $coverageData['insurance_carriers'][$coverageKey] = 'A';
                    } else {
                        $types = array_values(array_diff($types,[$coverageKey]));
                        unset($coverageData['insurance_carriers'][$coverageKey],$coverageData['coverage_options'][$coverageKey]);
                        if (in_array($coverageKey,['commercial_general_liability','physical_damage','motor_truck_cargo'],true)) unset($coverageData[$coverageKey]);
                        if ($coverageKey === 'automobile_liability') unset($coverageData['combined_single_limit']);
                    }
                    $coverageData['insurance_types'] = $types;
                    $coverageData['types_of_insurance_summary'] = implode(', ',array_map(static fn($t) => $coverageTypes[$t] ?? $t,$types));
                    $pdo->prepare('UPDATE policies SET coverage_data = :data WHERE id = :id AND insured_id = :insured_id')
                        ->execute(['data'=>json_encode($coverageData,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'id'=>(int)$policyId,'insured_id'=>$insuredId]);
                    $after = ['insurance_types'=>$types,'coverage'=>$coverageData[$coverageKey] ?? null,'insurance_carrier'=>$coverageData['insurance_carriers'][$coverageKey] ?? null,'coverage_data_snapshot'=>$coverageData];
                    $summary = ($actionType === 'coverage_add' ? 'Added ' : 'Removed ').$coverageTypes[$coverageKey].' coverage on policy '.$policy['policy_number'].'.';
                    $subjectType = 'policy';
                    $subjectId = (int)$policyId;
                    $detail = ['before'=>$before,'after'=>$after];
                } elseif ($actionType === 'coverage_amount_update') {
                    $coverageKey = (string)($_POST['coverage_type'] ?? '');
                    $field = (string)($_POST['coverage_amount_field'] ?? '');
                    $types = is_array($coverageData['insurance_types'] ?? null) ? array_values(array_map('strval',$coverageData['insurance_types'])) : [];
                    if (!isset($coverageTypes[$coverageKey]) || !in_array($coverageKey,$types,true)) throw new InvalidArgumentException('Select a coverage currently included in this policy.');
                    $fieldLabels = [
                        'commercial_general_liability'=>[
                            'each_occurrence'=>'Each Occurrence',
                            'damage_to_rented_premises'=>'Damage to Rented Premises',
                            'medical_expense'=>'Medical Expense',
                            'personal_advertising_injury'=>'Personal & Advertising Injury',
                            'general_aggregate'=>'General Aggregate',
                            'products_completed_operations_aggregate'=>'Products - Completed Operations Aggregate'
                        ],
                        'automobile_liability'=>['combined_single_limit'=>'Combined Single Limit'],
                        'physical_damage'=>['amount'=>'Physical Damage Amount'],
                        'motor_truck_cargo'=>['amount'=>'Motor Truck Cargo Amount'],
                        'umbrella_liability'=>['deductible_retention_amount'=>'Deductible / Retention Amount'],
                        'excess_liability'=>['deductible_retention_amount'=>'Deductible / Retention Amount']
                    ];
                    if (!isset($fieldLabels[$coverageKey][$field])) throw new InvalidArgumentException('This amount field is not available for the selected coverage.');
                    $newValue = endorsementAmount($_POST['coverage_new_amount'] ?? '',$fieldLabels[$coverageKey][$field]);
                    if ($coverageKey === 'commercial_general_liability') {
                        if (!is_array($coverageData[$coverageKey] ?? null)) $coverageData[$coverageKey] = [];
                        if (!is_array($coverageData[$coverageKey]['limits'] ?? null)) $coverageData[$coverageKey]['limits'] = [];
                        $oldValue = $coverageData[$coverageKey]['limits'][$field] ?? null;
                        $coverageData[$coverageKey]['limits'][$field] = $newValue;
                    } elseif ($field === 'combined_single_limit' || $field === 'deductible_retention_amount') {
                        $oldValue = $coverageData[$field] ?? null;
                        $coverageData[$field] = $newValue;
                    } else {
                        if (!is_array($coverageData[$coverageKey] ?? null)) $coverageData[$coverageKey] = [];
                        $oldValue = $coverageData[$coverageKey]['amount'] ?? null;
                        $coverageData[$coverageKey]['amount'] = $newValue;
                    }
                    $pdo->prepare('UPDATE policies SET coverage_data = :data WHERE id = :id AND insured_id = :insured_id')
                        ->execute(['data'=>json_encode($coverageData,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'id'=>(int)$policyId,'insured_id'=>$insuredId]);
                    $before = ['coverage'=>$coverageTypes[$coverageKey],'field'=>$field,'label'=>$fieldLabels[$coverageKey][$field],'value'=>$oldValue,'coverage_data_snapshot'=>$coverageDataBefore];
                    $after = ['coverage'=>$coverageTypes[$coverageKey],'field'=>$field,'label'=>$fieldLabels[$coverageKey][$field],'value'=>$newValue,'coverage_data_snapshot'=>$coverageData];
                    $summary = 'Updated '.$coverageTypes[$coverageKey].' / '.$fieldLabels[$coverageKey][$field].' on policy '.$policy['policy_number'].' from '.(string)($oldValue ?? 'not set').' to '.$newValue.'.';
                    $subjectType = 'policy';
                    $subjectId = (int)$policyId;
                    $detail = ['before'=>$before,'after'=>$after];
                } else {
                    throw new InvalidArgumentException('Choose a valid policy endorsement action.');
                }
            } else {
                throw new InvalidArgumentException('Choose Driver, Vehicle or Policy.');
            }

            $number = 'END-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $recipient = trim((string)($customer['email'] ?? ''));
            if ($recipient === '') {
                $q = $pdo->prepare("SELECT email FROM users WHERE insured_id = :insured_id AND role = 'customer' AND is_active = 1 ORDER BY id DESC LIMIT 1");
                $q->execute(['insured_id'=>$insuredId]);
                $recipient = trim((string)($q->fetchColumn() ?: ''));
            }
            $changes = [
                'summary'=>$summary,
                'before'=>$before,
                'after'=>$after,
                'detail'=>$detail,
                'customer'=>['id'=>$insuredId,'name'=>$customer['legal_name'],'dot_number'=>$customer['dot_number']],
                'policy'=>['id'=>(int)$policy['id'],'number'=>$policy['policy_number']]
            ];
            $q = $pdo->prepare("INSERT INTO endorsements (endorsement_number, insured_id, policy_id, category, action_type, subject_type, subject_record_id, summary, changes_json, actor_user_id, actor_role, email_to, email_status) VALUES (:number,:insured_id,:policy_id,:category,:action_type,:subject_type,:subject_id,:summary,:changes,:actor_id,:actor_role,:email_to,:email_status)");
            $q->execute([
                'number'=>$number,'insured_id'=>$insuredId,'policy_id'=>(int)$policyId,
                'category'=>$category,'action_type'=>$actionType,'subject_type'=>$subjectType,
                'subject_id'=>$subjectId,'summary'=>$summary,
                'changes'=>json_encode($changes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'actor_id'=>(int)$u['id'],'actor_role'=>(string)$u['role'],
                'email_to'=>$recipient !== '' ? $recipient : null,
                'email_status'=>$recipient !== '' ? 'pending' : 'no_email'
            ]);
            $endorsementId = (int)$pdo->lastInsertId();
            audit('endorsement','endorsements',$endorsementId,$number.' · '.$summary);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }

        $emailStatus = $recipient === '' ? 'no_email' : 'failed';
        $emailError = $recipient === '' ? 'No customer email address is stored for this insured.' : null;
        if ($recipient !== '' && filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
            try {
                $mailConfig = configFile('mail');
                $from = trim((string)($mailConfig['from_email'] ?? ''));
                if (!filter_var($from,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure a valid sender address in config/mail.php.');
                $fromName = str_replace(["\r","\n"],'',(string)($mailConfig['from_name'] ?? 'Ace Insurance Portal'));
                $subject = 'Endorsement Confirmation '.$number;
                $body = "Hello ".$customer['legal_name'].",\n\nYour policy endorsement has been processed.\n\nEndorsement: ".$number."\nPolicy Number: ".$policy['policy_number']."\nChange: ".$summary."\n\nPlease retain this email for your records. Contact your insurance agency if you have questions.\n\nRegards,\n".$fromName;
                $headers = "From: ".$fromName." <".$from.">\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: PHP/".PHP_VERSION;
                if (mail($recipient,$subject,$body,$headers)) {
                    $emailStatus = 'sent';
                    $emailError = null;
                } else {
                    $emailStatus = 'failed';
                    $emailError = 'PHP mail() did not accept the message. Check cPanel email delivery / SMTP settings.';
                }
            } catch (Throwable $mailException) {
                $emailStatus = 'failed';
                $emailError = mb_substr($mailException->getMessage(),0,500);
            }
        } elseif ($recipient !== '') {
            $emailStatus = 'failed';
            $emailError = 'The customer email address saved in the database is invalid.';
        }

        db()->prepare('UPDATE endorsements SET email_status=:status,email_error=:error,email_sent_at=:sent_at WHERE id=:id')
            ->execute([
                'status'=>$emailStatus,
                'error'=>$emailError,
                'sent_at'=>$emailStatus === 'sent' ? gmdate('Y-m-d H:i:s') : null,
                'id'=>$endorsementId
            ]);
        flash('success','Endorsement '.$number.' processed. Email status: '.str_replace('_',' ',$emailStatus).($emailError ? ' — '.$emailError : ''));
        redirect('/agency/endorsements.php?insured_id='.$insuredId);
    } catch (Throwable $exception) {
        error_log('Endorsement processing failed: '.$exception->getMessage());
        $error = $exception->getMessage();
    }
}

$listQuery = db()->query(
    'SELECT e.*, i.legal_name, i.dot_number, p.policy_number, u.full_name AS actor_name
     FROM endorsements e
     INNER JOIN insureds i ON i.id=e.insured_id
     LEFT JOIN policies p ON p.id=e.policy_id
     LEFT JOIN users u ON u.id=e.actor_user_id
     ORDER BY e.id DESC LIMIT 100'
);
$endorsementRows = $listQuery->fetchAll();
$formatAmount = static function($v): string {
    return $v === null || $v === '' || !is_numeric($v) ? '—' : '$'.number_format((float)$v,2,'.',',');
};
$categoryLabels = ['driver'=>'Driver','vehicle'=>'Vehicle','policy'=>'Policy'];
$selectedCategory = (string)($form['category'] ?? 'driver');
$selectedAction = (string)($form['action_type'] ?? '');
$selectedPolicyId = (int)($selectedPolicy['id'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Endorsement Management</title>
<link rel="stylesheet" href="<?=e(appUrl('/assets/app.css'))?>">
<script src="<?=e(appUrl('/assets/amount-format.js'))?>" defer></script>
<script src="<?=e(appUrl('/assets/us-date-format.js'))?>" defer></script>
</head>
<body>
<header class="topbar">
  <div><strong>ACE Insurance</strong><span>Agency Portal</span></div>
  <div><?=e($u['full_name'])?> · <?=e($u['role'])?> <a class="btn light small-btn" href="<?=e(appUrl('/agency/change-password.php'))?>">Change Password</a><a class="btn light small-btn" href="<?=e(appUrl('/logout.php'))?>">Logout</a></div>
</header>
<div class="layout">
  <aside class="sidebar">
    <a href="<?=e(appUrl('/agency/index.php'))?>">Dashboard</a>
    <a href="<?=e(appUrl('/agency/finance.php'))?>">Finance Billing Manage</a>
    <a href="<?=e(appUrl('/agency/claims.php'))?>">Claims Management</a>
    <?php foreach(['insureds'=>'Insured Customers','vehicles'=>'Vehicles','policies'=>'Policies','drivers'=>'Drivers'] as $k=>$label):?>
      <a href="<?=e(appUrl('/agency/crud.php?entity='.$k))?>"><?=e($label)?></a>
    <?php endforeach;?>
    <a href="<?=e(appUrl('/agency/endorsements.php'))?>" class="selected">Endorsements</a>
    <?php if($u['role']==='admin'):?>
      <a href="<?=e(appUrl('/agency/own-company.php'))?>">Own Company</a>
      <a href="<?=e(appUrl('/agency/staff.php'))?>">Staff Accounts</a>
      <a href="<?=e(appUrl('/agency/audit-logs.php'))?>">Activity Logs</a>
    <?php endif;?>
  </aside>
  <main class="main endorsement-main">
    <section class="endorsement-hero">
      <div><span class="endorsement-eyebrow">POLICY CHANGE WORKSPACE</span><h1>Endorsement Management</h1><p>Process mid-term changes, retain a full history and notify the insured customer.</p></div>
      <div class="endorsement-hero-stat"><strong><?=number_format(count($endorsementRows))?></strong><span>Recent endorsements</span></div>
    </section>
    <?php if($flash):?><div class="alert success"><?=e((string)$flash['message'])?></div><?php endif;?>
    <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>

    <section class="panel endorsement-customer-panel">
      <div class="endorsement-section-heading"><div><span class="endorsement-step">STEP 1</span><h2>Select Customer</h2><p>Choose the insured customer requesting a mid-term change.</p></div></div>
      <form method="get" class="endorsement-customer-form">
        <label>Customer
          <select name="insured_id" required>
            <option value="">Select a customer...</option>
            <?php foreach($customers as $item):?>
              <option value="<?=$item['id']?>" <?=$insuredId===(int)$item['id']?'selected':''?>><?=e($item['legal_name'].' · DOT '.$item['dot_number'])?></option>
            <?php endforeach;?>
          </select>
        </label>
        <button class="btn primary" type="submit">Continue <span aria-hidden="true">→</span></button>
      </form>
      <?php if($customer):?>
        <div class="endorsement-customer-summary">
          <div><span>Selected customer</span><strong><?=e($customer['legal_name'])?></strong><small>DOT <?=e($customer['dot_number'])?></small></div>
          <div><span>Customer email</span><strong><?=e((string)($customer['email'] ?: 'Not saved on insured record'))?></strong><small>Customer portal email will be used as fallback</small></div>
          <div><span>Current records</span><strong><?=count($policies)?> policies</strong><small><?=count($drivers)?> active drivers · <?=count($vehicles)?> active vehicles</small></div>
        </div>
      <?php endif;?>
    </section>

    <?php if($customer && !$policies):?>
      <div class="alert info">This customer has no policy record. Create a policy before processing an endorsement.</div>
    <?php elseif($customer):?>
      <form method="post" class="endorsement-workflow-form" id="endorsement-form">
        <input type="hidden" name="_csrf" value="<?=e(csrfToken())?>">
        <input type="hidden" name="insured_id" value="<?=$insuredId?>">
        <section class="panel endorsement-policy-panel">
          <div class="endorsement-section-heading"><div><span class="endorsement-step">STEP 2</span><h2>Choose Policy</h2><p>Each endorsement is attached to the selected policy.</p></div></div>
          <label>Policy
            <select name="policy_id" required>
              <?php foreach($policies as $p):?>
                <option value="<?=$p['id']?>" <?=((int)($form['policy_id']??$selectedPolicyId)===(int)$p['id'])?'selected':''?>><?=e($p['policy_number'].' · '.($p['business_line'] ?: 'Policy').' · '.ucfirst((string)$p['status']))?></option>
              <?php endforeach;?>
            </select>
          </label>
        </section>

        <section class="panel endorsement-type-panel">
          <div class="endorsement-section-heading"><div><span class="endorsement-step">STEP 3</span><h2>What needs to change?</h2><p>Choose the area where the customer requested an endorsement.</p></div></div>
          <div class="endorsement-type-grid">
            <?php foreach($categoryLabels as $key=>$label):?>
              <label class="endorsement-type-card <?=$selectedCategory===$key?'is-selected':''?>">
                <input type="radio" name="category" value="<?=e($key)?>" <?=$selectedCategory===$key?'checked':''?>>
                <span class="endorsement-type-icon" aria-hidden="true"><?=$key==='driver'?'♙':($key==='vehicle'?'▣':'▤')?></span>
                <strong><?=e($label)?></strong>
                <small><?=$key==='driver'?'Add or remove a driver':($key==='vehicle'?'Add, remove or change vehicle value':'Change premium or policy coverage')?></small>
              </label>
            <?php endforeach;?>
          </div>
          <label class="endorsement-action-label">Select action
            <select name="action_type" id="endorsement-action" required><option value="">Choose an action...</option></select>
          </label>
        </section>

        <section class="panel endorsement-detail-panel" id="endorsement-detail-panel" hidden>
          <div class="endorsement-section-heading"><div><span class="endorsement-step">STEP 4</span><h2>Change details</h2><p>Enter or select the requested changes.</p></div></div>

          <div class="endorsement-fields" data-category="driver" data-action="add" hidden>
            <h3>Add Driver</h3>
            <label>Driver Full Name<input name="driver_full_name" data-required maxlength="180" value="<?=e((string)($form['driver_full_name']??''))?>"></label>
            <label>License Number<input name="driver_license_number" maxlength="100" value="<?=e((string)($form['driver_license_number']??''))?>"></label>
            <label>License State
              <select name="driver_license_state"><option value="">Select state</option>
                <?php foreach(Crud::US_LICENSE_STATES as $code=>$name):?><option value="<?=e($name)?>" <?=((string)($form['driver_license_state']??'')===$name)?'selected':''?>><?=e($name)?></option><?php endforeach;?>
              </select>
            </label>
            <label>Date of Birth<input type="date" name="driver_date_of_birth" value="<?=e((string)($form['driver_date_of_birth']??''))?>"></label>
            <label>Excluded<select name="driver_is_excluded"><option value="0" <?=((string)($form['driver_is_excluded']??'0')==='0')?'selected':''?>>No</option><option value="1" <?=((string)($form['driver_is_excluded']??'')==='1')?'selected':''?>>Yes</option></select></label>
          </div>

          <div class="endorsement-fields" data-category="driver" data-action="remove" hidden>
            <h3>Remove Driver</h3>
            <label>Select active driver
              <select name="driver_id" data-required><option value="">Select driver...</option>
                <?php foreach($drivers as $d):?><option value="<?=$d['id']?>" <?=((string)($form['driver_id']??'')===(string)$d['id'])?'selected':''?>><?=e($d['full_name'].($d['license_number']?' · '.$d['license_number']:''))?></option><?php endforeach;?>
              </select>
            </label>
            <p class="endorsement-help">The driver is marked inactive instead of permanently deleted. Historic records remain available.</p>
          </div>

          <div class="endorsement-fields" data-category="vehicle" data-action="add" hidden>
            <h3>Add Vehicle</h3>
            <label>VIN<input name="vehicle_vin" data-required maxlength="50" value="<?=e((string)($form['vehicle_vin']??''))?>"></label>
            <label>Unit Number<input name="vehicle_unit_number" maxlength="40" value="<?=e((string)($form['vehicle_unit_number']??''))?>"></label>
            <label>Vehicle Type<input name="vehicle_type" maxlength="80" value="<?=e((string)($form['vehicle_type']??''))?>"></label>
            <label>Make<input name="vehicle_make" maxlength="80" value="<?=e((string)($form['vehicle_make']??''))?>"></label>
            <label>Model<input name="vehicle_model" maxlength="80" value="<?=e((string)($form['vehicle_model']??''))?>"></label>
            <label>Model Year<input type="number" name="vehicle_model_year" min="1900" max="2100" value="<?=e((string)($form['vehicle_model_year']??''))?>"></label>
            <label>Vehicle Value ($)<input type="text" inputmode="decimal" data-amount-format name="vehicle_value_amount" value="<?=e((string)($form['vehicle_value_amount']??''))?>"></label>
          </div>

          <div class="endorsement-fields" data-category="vehicle" data-action="remove" hidden>
            <h3>Remove Vehicle</h3>
            <label>Select active vehicle
              <select name="vehicle_id" data-required><option value="">Select vehicle...</option>
                <?php foreach($vehicles as $v):?><option value="<?=$v['id']?>" <?=((string)($form['vehicle_id']??'')===(string)$v['id'])?'selected':''?>><?=e(($v['unit_number']?'Unit '.$v['unit_number'].' · ':'').$v['vin'].' · '.trim(($v['model_year']?$v['model_year'].' ':'').($v['make']??'').' '.($v['model']??'')))?></option><?php endforeach;?>
              </select>
            </label>
            <p class="endorsement-help">The vehicle is marked inactive rather than physically deleted.</p>
          </div>

          <div class="endorsement-fields" data-category="vehicle" data-action="value_update" hidden>
            <h3>Update Vehicle Value</h3>
            <label>Select active vehicle
              <select name="vehicle_id" data-required><option value="">Select vehicle...</option>
                <?php foreach($vehicles as $v):?><option value="<?=$v['id']?>" <?=((string)($form['vehicle_id']??'')===(string)$v['id'])?'selected':''?>><?=e(($v['unit_number']?'Unit '.$v['unit_number'].' · ':'').$v['vin'].' · Current '.$formatAmount($v['value_amount']))?></option><?php endforeach;?>
              </select>
            </label>
            <label>New Vehicle Value ($)<input type="text" inputmode="decimal" data-amount-format data-required name="vehicle_new_value" value="<?=e((string)($form['vehicle_new_value']??''))?>"></label>
          </div>

          <div class="endorsement-fields" data-category="policy" data-action="amount_update" hidden>
            <h3>Update Policy Amount</h3>
            <label>Amount Field<select name="policy_amount_field"><option value="written_premium">Written Premium</option><option value="estimated_fee">Estimated Fee</option><option value="estimated_tax">Estimated Tax</option><option value="total_premium">Total Premium</option></select></label>
            <label>New Amount ($)<input type="text" inputmode="decimal" data-amount-format data-required name="policy_new_amount" value="<?=e((string)($form['policy_new_amount']??''))?>"></label>
          </div>

          <div class="endorsement-fields" data-category="policy" data-action="coverage_add" hidden>
            <h3>Add Coverage</h3>
            <label>Coverage to Add<select name="coverage_type" data-required><option value="">Select coverage...</option><?php foreach($coverageTypes as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select></label>
            <p class="endorsement-help">The coverage type will be added to this policy. Insurer A is set as the initial carrier; carrier and detailed limits can be reviewed in the Policy section.</p>
          </div>

          <div class="endorsement-fields" data-category="policy" data-action="coverage_remove" hidden>
            <h3>Remove Coverage</h3>
            <label>Coverage to Remove<select name="coverage_type" data-required><option value="">Select coverage...</option><?php foreach($coverageTypes as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select></label>
            <p class="endorsement-help">Only the chosen coverage is removed from the policy. Before and after data is retained in the endorsement history.</p>
          </div>

          <div class="endorsement-fields" data-category="policy" data-action="coverage_amount_update" hidden>
            <h3>Update Coverage Amount</h3>
            <label>Coverage<select name="coverage_type" id="endorsement-coverage-type" data-required><option value="">Select coverage...</option><?php foreach($coverageTypes as $key=>$label):?><option value="<?=e($key)?>"><?=e($label)?></option><?php endforeach;?></select></label>
            <label>Amount Field
              <select name="coverage_amount_field" id="endorsement-coverage-amount-field" data-required>
                <option value="each_occurrence" data-coverage="commercial_general_liability">Each Occurrence</option>
                <option value="damage_to_rented_premises" data-coverage="commercial_general_liability">Damage to Rented Premises</option>
                <option value="medical_expense" data-coverage="commercial_general_liability">Medical Expense</option>
                <option value="personal_advertising_injury" data-coverage="commercial_general_liability">Personal & Advertising Injury</option>
                <option value="general_aggregate" data-coverage="commercial_general_liability">General Aggregate</option>
                <option value="products_completed_operations_aggregate" data-coverage="commercial_general_liability">Products - Completed Operations Aggregate</option>
                <option value="combined_single_limit" data-coverage="automobile_liability">Combined Single Limit</option>
                <option value="amount" data-coverage="physical_damage">Physical Damage Amount</option>
                <option value="amount" data-coverage="motor_truck_cargo">Motor Truck Cargo Amount</option>
                <option value="deductible_retention_amount" data-coverage="umbrella_liability">Deductible / Retention Amount</option>
                <option value="deductible_retention_amount" data-coverage="excess_liability">Deductible / Retention Amount</option>
              </select>
            </label>
            <label>New Coverage Amount ($)<input type="text" inputmode="decimal" data-amount-format data-required name="coverage_new_amount" value="<?=e((string)($form['coverage_new_amount']??''))?>"></label>
          </div>
        </section>

        <section class="panel endorsement-review-panel" id="endorsement-review-panel" hidden>
          <div class="endorsement-section-heading"><div><span class="endorsement-step">STEP 5</span><h2>Review & Submit</h2><p>Check the customer, policy and requested change.</p></div></div>
          <div class="endorsement-review-grid">
            <div><span>Customer</span><strong><?=e($customer['legal_name'])?></strong></div>
            <div><span>Policy</span><strong id="endorsement-review-policy"><?=e((string)($selectedPolicy['policy_number']??''))?></strong></div>
            <div><span>Change Area</span><strong id="endorsement-review-category">Driver</strong></div>
            <div><span>Action</span><strong id="endorsement-review-action">Choose an action</strong></div>
          </div>
          <div class="endorsement-email-note">An email notification will be attempted to the customer's registered email. Email status is retained with the endorsement history.</div>
          <div class="endorsement-form-actions"><button class="btn primary" type="submit">Submit Endorsement</button><a class="btn light" href="<?=e(appUrl('/agency/endorsements.php?insured_id='.$insuredId))?>">Cancel</a></div>
        </section>
      </form>
    <?php endif;?>

    <section class="panel endorsement-history-panel">
      <div class="endorsement-section-heading endorsement-history-heading">
        <div><span class="endorsement-step">HISTORY</span><h2>Endorsement History</h2><p>Latest 100 changes across customers.</p></div>
        <span class="endorsement-history-count"><?=number_format(count($endorsementRows))?> records</span>
      </div>
      <div class="table-wrap">
        <table class="endorsement-table">
          <thead><tr><th>Endorsement</th><th>Customer</th><th>Policy</th><th>Change</th><th>Processed By</th><th>Email</th><th>Date (UTC)</th><th>Details</th></tr></thead>
          <tbody>
          <?php foreach($endorsementRows as $row):?>
            <?php $decoded=json_decode((string)$row['changes_json'],true);$decoded=is_array($decoded)?$decoded:[];?>
            <tr>
              <td><strong><?=e($row['endorsement_number'])?></strong></td>
              <td><?=e($row['legal_name'])?><small class="endorsement-table-sub">DOT <?=e($row['dot_number'])?></small></td>
              <td><?=e((string)($row['policy_number']??'—'))?></td>
              <td><span class="endorsement-category-pill"><?=e(ucfirst((string)$row['category']))?></span><small class="endorsement-table-sub"><?=e($row['summary'])?></small></td>
              <td><?=e((string)($row['actor_name']??'Deleted user'))?><small class="endorsement-table-sub"><?=e(ucfirst((string)$row['actor_role']))?></small></td>
              <td><span class="endorsement-email-status is-<?=e((string)$row['email_status'])?>"><?=e(str_replace('_',' ',(string)$row['email_status']))?></span><?php if(!empty($row['email_to'])):?><small class="endorsement-table-sub"><?=e($row['email_to'])?></small><?php endif;?></td>
              <td><?=e((string)$row['created_at'])?></td>
              <td><details class="endorsement-details"><summary>View</summary><pre><?=e(json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)?:'{}')?></pre><?php if(!empty($row['email_error'])):?><p class="endorsement-email-error"><?=e($row['email_error'])?></p><?php endif;?></details></td>
            </tr>
          <?php endforeach;?>
          <?php if(!$endorsementRows):?><tr><td colspan="8" class="muted endorsement-empty">No endorsements have been processed yet.</td></tr><?php endif;?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>
<script>
(function () {
  const form = document.getElementById('endorsement-form');
  if (!form) return;
  const categories = Array.from(form.querySelectorAll('input[name="category"]'));
  const action = document.getElementById('endorsement-action');
  const detailPanel = document.getElementById('endorsement-detail-panel');
  const reviewPanel = document.getElementById('endorsement-review-panel');
  const sections = Array.from(form.querySelectorAll('.endorsement-fields'));
  const labels = {driver:'Driver',vehicle:'Vehicle',policy:'Policy'};
  const options = {
    driver:[['add','Add Driver'],['remove','Remove Driver']],
    vehicle:[['add','Add Vehicle'],['remove','Remove Vehicle'],['value_update','Update Vehicle Value']],
    policy:[['amount_update','Update Policy Amount'],['coverage_add','Add Coverage'],['coverage_remove','Remove Coverage'],['coverage_amount_update','Update Coverage Amount']]
  };
  const actionLabels = {};
  Object.keys(options).forEach(function (key) { options[key].forEach(function (item) { actionLabels[item[0]]=item[1]; }); });

  function category() {
    const selected = categories.find(function (item) { return item.checked; });
    return selected ? selected.value : '';
  }

  function updateCoverageAmountFields() {
    const coverage = document.getElementById('endorsement-coverage-type');
    const field = document.getElementById('endorsement-coverage-amount-field');
    if (!coverage || !field) return;
    const available = Array.from(field.options).filter(function (option) { return option.dataset.coverage === coverage.value; });
    Array.from(field.options).forEach(function (option) {
      const shown = available.indexOf(option) !== -1;
      option.hidden = !shown;
      option.disabled = !shown;
    });
    if (!available.some(function (option) { return option.value === field.value && !option.disabled; })) {
      field.value = available.length ? available[0].value : '';
    }
  }

  function render() {
    const cat = category();
    const previousAction = action.value;
    action.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose an action...';
    action.appendChild(placeholder);
    (options[cat] || []).forEach(function (item) {
      const option = document.createElement('option');
      option.value = item[0];
      option.textContent = item[1];
      action.appendChild(option);
    });
    if ((options[cat] || []).some(function (item) { return item[0] === previousAction; })) action.value = previousAction;

    const activeSection = sections.find(function (section) {
      return section.dataset.category === cat && section.dataset.action === action.value;
    }) || null;
    sections.forEach(function (section) {
      const active = section === activeSection;
      section.hidden = !active;
      section.querySelectorAll('input,select,textarea').forEach(function (control) {
        control.disabled = !active;
        control.required = active && control.hasAttribute('data-required');
      });
    });
    detailPanel.hidden = !activeSection;
    reviewPanel.hidden = !activeSection;
    const policySelect = form.querySelector('select[name="policy_id"]');
    const policyText = policySelect && policySelect.selectedOptions[0] ? policySelect.selectedOptions[0].textContent : '—';
    document.getElementById('endorsement-review-policy').textContent = policyText;
    document.getElementById('endorsement-review-category').textContent = labels[cat] || '—';
    document.getElementById('endorsement-review-action').textContent = actionLabels[action.value] || 'Choose an action';
    updateCoverageAmountFields();
  }

  categories.forEach(function (item) {
    item.addEventListener('change', function () {
      document.querySelectorAll('.endorsement-type-card').forEach(function (card) {
        card.classList.toggle('is-selected', card.querySelector('input').checked);
      });
      render();
    });
  });
  action.addEventListener('change', render);
  const coverage = document.getElementById('endorsement-coverage-type');
  if (coverage) coverage.addEventListener('change', updateCoverageAmountFields);
  const oldAction = <?=json_encode($selectedAction,JSON_UNESCAPED_SLASHES)?>;
  if (oldAction) action.value = oldAction;
  render();
})();
</script>
</body>
</html>
