<?php

declare(strict_types=1);

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_STATISTIC', true);
define('STOP_STATISTICS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Config\Option;
use Project\Integration\Division\Logger;
use Project\Integration\Division\QueueRepository;

header('Content-Type: application/json; charset=utf-8');

function sendJsonResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    die();
}

// 1. Проверка метода и Bearer-токена
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJsonResponse(405, ['RESULT' => 'error', 'MESSAGE' => 'Method Not Allowed']);
}

$expectedToken = \Option::get('main', '1c_division_import_token', '');
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

if ($expectedToken === '' || !preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches) || !hash_equals($expectedToken, $matches[1])) {
    sendJsonResponse(401, ['RESULT' => 'error', 'MESSAGE' => 'Unauthorized']);
}

$logger = new Logger('webhook');

try {
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        sendJsonResponse(400, ['RESULT' => 'error', 'MESSAGE' => 'Empty request body']);
    }

    // 2. Парсинг JSON с выбросом исключения при ошибке синтаксиса
    $decoded = json_decode($rawInput, true, 3, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || empty($decoded)) {
        sendJsonResponse(400, ['RESULT' => 'error', 'MESSAGE' => 'Invalid JSON payload']);
    }

    // 3. Нормализация типов (защита от TypeError в PHP 8.2)
    $payload = [];
    foreach ($decoded as $login => $code) {
        if (is_string($login) && is_scalar($code) && $login !== '' && (string)$code !== '') {
            $payload[trim($login)] = trim((string)$code);
        }
    }

    if (empty($payload)) {
        sendJsonResponse(422, ['RESULT' => 'error', 'MESSAGE' => 'No valid key-value pairs found']);
    }

    // 4. Постановка в очередь Highload-блока
    $queue = new QueueRepository();
    $taskId = $queue->push($payload);

    $logger->info('Batch queued', ['task_id' => $taskId, 'count' => count($payload)]);

    sendJsonResponse(202, [
        'RESULT' => 'accepted',
        'TASK_ID' => $taskId,
        'ALL' => count($payload),
    ]);

} catch (JsonException) {
    sendJsonResponse(400, ['RESULT' => 'error', 'MESSAGE' => 'Malformed JSON']);
} catch (Throwable $e) {
    $logger->error('Webhook error: ' . $e->getMessage());
    sendJsonResponse(500, ['RESULT' => 'error', 'MESSAGE' => 'Internal Server Error']);
}
