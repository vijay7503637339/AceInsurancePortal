<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use AcePortal\Auth;

Auth::requireRoles(['admin', 'staff']);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$insuredId = filter_var($_GET['insured_id'] ?? null, FILTER_VALIDATE_INT);
if (!$insuredId || $insuredId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a valid insured customer.', 'carriers' => []]);
    exit;
}

try {
    $statement = db()->prepare(
        'SELECT id, policy_number,
                insurer_a_name, insurer_a_naic,
                insurer_b_name, insurer_b_naic,
                insurer_c_name, insurer_c_naic,
                insurer_d_name, insurer_d_naic,
                insurer_e_name, insurer_e_naic,
                insurer_f_name, insurer_f_naic
         FROM policies
         WHERE insured_id = :insured_id
         ORDER BY id DESC'
    );
    $statement->execute(['insured_id' => (int)$insuredId]);
    $policies = $statement->fetchAll();

    $carriers = [];
    foreach ($policies as $policy) {
        foreach (range('A', 'F') as $letter) {
            $suffix = strtolower($letter);
            $name = trim((string)($policy['insurer_' . $suffix . '_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $naic = trim((string)($policy['insurer_' . $suffix . '_naic'] ?? ''));
            $policyNumber = trim((string)($policy['policy_number'] ?? ''));
            $label = 'Insurer ' . $letter . ' · ' . $name;
            if ($policyNumber !== '') {
                $label .= ' · Policy #' . $policyNumber;
            }
            if ($naic !== '') {
                $label .= ' · NAIC ' . $naic;
            }

            $carriers[] = [
                'key' => (int)$policy['id'] . ':' . $letter,
                'policy_id' => (int)$policy['id'],
                'policy_number' => $policyNumber,
                'letter' => $letter,
                'name' => $name,
                'naic' => $naic,
                'label' => $label,
            ];
        }
    }

    echo json_encode(['carriers' => $carriers], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Could not load customer policy carriers: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load this customer’s insurance carriers. Please try again.']);
}
