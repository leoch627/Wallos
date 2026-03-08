<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(["status" => "Error", "message" => "CSV file is required"]);
    exit;
}

$mode = $_POST['mode'] ?? 'skip'; // skip|update
$defaultType = validate($_POST['default_subscription_type'] ?? 'general');

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
if ($handle === false) {
    echo json_encode(["status" => "Error", "message" => "Failed to read CSV file"]);
    exit;
}

$header = fgetcsv($handle);
if (!$header) {
    fclose($handle);
    echo json_encode(["status" => "Error", "message" => "CSV header row is missing"]);
    exit;
}

$normalize = function ($v) {
    $v = strtolower(trim((string)$v));
    $v = str_replace(['-', ' ', '.'], '_', $v);
    return $v;
};

$map = [];
foreach ($header as $idx => $col) {
    $map[$normalize($col)] = $idx;
}

$get = function ($row, $keys, $default = '') use ($map) {
    foreach ($keys as $key) {
        if (isset($map[$key])) {
            return trim((string)($row[$map[$key]] ?? ''));
        }
    }
    return $default;
};

$insertSql = "INSERT INTO subscriptions (
    name, logo, price, currency_id, next_payment, cycle, frequency, notes,
    payment_method_id, payer_user_id, category_id, notify, inactive, url,
    notify_days_before, user_id, cancellation_date, replacement_subscription_id,
    auto_renew, start_date, subscription_type, provider, region, external_id, plan_details
) VALUES (
    :name, '', :price, :currency_id, :next_payment, :cycle, :frequency, :notes,
    :payment_method_id, :payer_user_id, :category_id, :notify, :inactive, :url,
    :notify_days_before, :user_id, NULL, NULL,
    :auto_renew, :start_date, :subscription_type, :provider, :region, :external_id, :plan_details
)";

$updateSql = "UPDATE subscriptions SET
    price=:price, currency_id=:currency_id, next_payment=:next_payment, cycle=:cycle,
    frequency=:frequency, notes=:notes, payment_method_id=:payment_method_id,
    payer_user_id=:payer_user_id, category_id=:category_id, notify=:notify,
    inactive=:inactive, url=:url, notify_days_before=:notify_days_before,
    auto_renew=:auto_renew, start_date=:start_date, subscription_type=:subscription_type,
    provider=:provider, region=:region, external_id=:external_id, plan_details=:plan_details
    WHERE id=:id AND user_id=:user_id";

$added = 0;
$updated = 0;
$skipped = 0;
$lineNo = 1;

while (($row = fgetcsv($handle)) !== false) {
    $lineNo++;

    $name = validate($get($row, ['name', 'subscription_name']));
    if ($name === '') {
        $skipped++;
        continue;
    }

    $provider = validate($get($row, ['provider', 'vendor', 'merchant']));
    $subscriptionType = validate($get($row, ['subscription_type', 'type', 'service_type'], $defaultType));
    if ($subscriptionType === '') {
        $subscriptionType = 'general';
    }

    $price = (float)$get($row, ['price', 'amount'], '0');
    $currencyId = (int)$get($row, ['currency_id'], '1');
    $nextPayment = $get($row, ['next_payment', 'next_payment_date', 'renewal_date']);
    $startDate = $get($row, ['start_date']);
    $frequency = (int)$get($row, ['frequency'], '1');
    $cycle = (int)$get($row, ['cycle', 'cycle_id'], '3');
    $categoryId = (int)$get($row, ['category_id'], '1');
    $paymentMethodId = (int)$get($row, ['payment_method_id'], '1');
    $payerUserId = (int)$get($row, ['payer_user_id'], '1');
    $notifyDaysBefore = (int)$get($row, ['notify_days_before'], '-1');
    $autoRenew = (int)$get($row, ['auto_renew'], '1') ? 1 : 0;
    $notify = (int)$get($row, ['notify'], '0') ? 1 : 0;
    $inactive = (int)$get($row, ['inactive'], '0') ? 1 : 0;
    $url = validate($get($row, ['url', 'website']));
    $notes = validate($get($row, ['notes', 'note']));
    $region = validate($get($row, ['region', 'country', 'location']));
    $externalId = validate($get($row, ['external_id', 'id', 'line_id', 'instance_id']));
    $planDetails = validate($get($row, ['plan_details', 'plan', 'spec', 'specs', 'data_quota', 'bandwidth']));

    $findSql = "SELECT id FROM subscriptions WHERE user_id=:user_id AND lower(name)=lower(:name)";
    if ($provider !== '') {
        $findSql .= " AND lower(coalesce(provider, ''))=lower(:provider)";
    }
    $findStmt = $db->prepare($findSql);
    $findStmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $findStmt->bindValue(':name', $name, SQLITE3_TEXT);
    if ($provider !== '') {
        $findStmt->bindValue(':provider', $provider, SQLITE3_TEXT);
    }
    $existing = $findStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($existing && $mode === 'skip') {
        $skipped++;
        continue;
    }

    if ($existing && $mode === 'update') {
        $stmt = $db->prepare($updateSql);
        $stmt->bindValue(':id', (int)$existing['id'], SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare($insertSql);
    }

    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
    $stmt->bindValue(':currency_id', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':next_payment', $nextPayment, SQLITE3_TEXT);
    $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
    $stmt->bindValue(':frequency', $frequency, SQLITE3_INTEGER);
    $stmt->bindValue(':notes', $notes, SQLITE3_TEXT);
    $stmt->bindValue(':payment_method_id', $paymentMethodId, SQLITE3_INTEGER);
    $stmt->bindValue(':payer_user_id', $payerUserId, SQLITE3_INTEGER);
    $stmt->bindValue(':category_id', $categoryId, SQLITE3_INTEGER);
    $stmt->bindValue(':notify', $notify, SQLITE3_INTEGER);
    $stmt->bindValue(':inactive', $inactive, SQLITE3_INTEGER);
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $stmt->bindValue(':notify_days_before', $notifyDaysBefore, SQLITE3_INTEGER);
    $stmt->bindValue(':auto_renew', $autoRenew, SQLITE3_INTEGER);
    $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
    $stmt->bindValue(':subscription_type', $subscriptionType, SQLITE3_TEXT);
    $stmt->bindValue(':provider', $provider, SQLITE3_TEXT);
    $stmt->bindValue(':region', $region, SQLITE3_TEXT);
    $stmt->bindValue(':external_id', $externalId, SQLITE3_TEXT);
    $stmt->bindValue(':plan_details', $planDetails, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);

    $ok = $stmt->execute();
    if ($ok) {
        if ($existing && $mode === 'update') {
            $updated++;
        } else {
            $added++;
        }
    } else {
        $skipped++;
    }
}

fclose($handle);

echo json_encode([
    "status" => "Success",
    "message" => "Import complete. Added: $added, Updated: $updated, Skipped: $skipped"
]);
$db->close();
