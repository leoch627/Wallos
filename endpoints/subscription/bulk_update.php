<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

$filterType = validate($_POST['filter_type'] ?? '');
$filterProvider = validate($_POST['filter_provider'] ?? '');

$set = [];
$params = [':userId' => [$userId, SQLITE3_INTEGER]];

if (isset($_POST['set_notify_days_before']) && $_POST['set_notify_days_before'] !== '') {
    $set[] = 'notify_days_before = :notifyDaysBefore';
    $params[':notifyDaysBefore'] = [(int)$_POST['set_notify_days_before'], SQLITE3_INTEGER];
}

if (isset($_POST['set_category_id']) && $_POST['set_category_id'] !== '') {
    $set[] = 'category_id = :categoryId';
    $params[':categoryId'] = [(int)$_POST['set_category_id'], SQLITE3_INTEGER];
}

if (isset($_POST['set_auto_renew']) && $_POST['set_auto_renew'] !== '') {
    $set[] = 'auto_renew = :autoRenew';
    $params[':autoRenew'] = [(int)$_POST['set_auto_renew'], SQLITE3_INTEGER];
}

if (isset($_POST['set_subscription_type']) && $_POST['set_subscription_type'] !== '') {
    $set[] = 'subscription_type = :subscriptionType';
    $params[':subscriptionType'] = [validate($_POST['set_subscription_type']), SQLITE3_TEXT];
}

if (isset($_POST['append_tag']) && trim($_POST['append_tag']) !== '') {
    $tag = trim($_POST['append_tag']);
    $set[] = "notes = CASE WHEN notes IS NULL OR notes = '' THEN :tag ELSE notes || ' #' || :tag END";
    $params[':tag'] = [$tag, SQLITE3_TEXT];
}

if (count($set) === 0) {
    echo json_encode(["status" => "Error", "message" => "Nothing selected to update"]);
    exit;
}

$sql = 'UPDATE subscriptions SET ' . implode(', ', $set) . ' WHERE user_id = :userId';

if ($filterType !== '') {
    $sql .= ' AND coalesce(subscription_type, "general") = :filterType';
    $params[':filterType'] = [$filterType, SQLITE3_TEXT];
}

if ($filterProvider !== '') {
    $sql .= ' AND lower(coalesce(provider, "")) = lower(:filterProvider)';
    $params[':filterProvider'] = [$filterProvider, SQLITE3_TEXT];
}

$stmt = $db->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value[0], $value[1]);
}

$ok = $stmt->execute();
if (!$ok) {
    echo json_encode(["status" => "Error", "message" => 'Bulk update failed: ' . $db->lastErrorMsg()]);
    exit;
}

$affected = $db->changes();
echo json_encode(["status" => "Success", "message" => "Bulk update complete. Updated {$affected} subscriptions."]);
$db->close();
