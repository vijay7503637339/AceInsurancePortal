<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';

use AcePortal\Auth;

Auth::requireRoles(['admin','staff']);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$claimId = filter_var($_GET['claim_id'] ?? null, FILTER_VALIDATE_INT);
if (!$claimId || $claimId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid claim ID is required.']);
    exit;
}

try {
    $claimQuery = db()->prepare('SELECT id, claim_number FROM claims WHERE id = :id LIMIT 1');
    $claimQuery->execute(['id' => $claimId]);
    $claim = $claimQuery->fetch();
    if (!$claim) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Claim not found.']);
        exit;
    }

    $query = db()->prepare(
        'SELECT id, actor_name, actor_role, event_type, changes_json, created_at
         FROM claim_change_logs
         WHERE claim_id = :claim_id
         ORDER BY created_at DESC, id DESC
'
    );
    $query->execute(['claim_id' => $claimId]);
    $logs = [];
    foreach ($query->fetchAll() as $row) {
        $changes = json_decode((string) $row['changes_json'], true);
        $logs[] = [
            'id' => (int) $row['id'],
            'actor_name' => (string) $row['actor_name'],
            'actor_role' => (string) $row['actor_role'],
            'event_type' => (string) $row['event_type'],
            'created_at' => (string) $row['created_at'],
            'changes' => is_array($changes) ? $changes : [],
        ];
    }

    echo json_encode([
        'success' => true,
        'claim_number' => (string) $claim['claim_number'],
        'logs' => $logs,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('Claim logs endpoint error: '.$error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load claim history.']);
}
