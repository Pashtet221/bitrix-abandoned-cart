<?php
// This endpoint intentionally returns only generic errors and never logs personal data.
define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_bitrix_sessid()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Invalid session']);
        exit;
    }
    if (!\Bitrix\Main\Loader::includeModule('pv.abandonedcart')) {
        throw new \RuntimeException('Module unavailable');
    }
    // FormData is used by the JS; no JSON request body is needed.
    $result = \PV\AbandonedCart\Checkout::capture($_POST);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Capture failed']);
}
