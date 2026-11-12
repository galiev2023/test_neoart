<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../');

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('STOP_STATISTICS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Project\Integration\Division\DivisionBatchUpdater;
use Project\Integration\Division\Logger;
use Project\Integration\Division\QueueRepository;

// Защита от наложения процессов
$lockFile = sys_get_temp_dir() . '/division_import_worker.lock';
$lockFp = fopen($lockFile, 'c');

if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0); // Другой воркер уже работает
}

$logger = new Logger('worker');

try {
    $queue = new QueueRepository();
    $updater = new DivisionBatchUpdater($logger);

    // Обрабатываем до 5 пакетов за один запуск
    $maxTasksPerRun = 5;
    $processedTasks = 0;

    while ($processedTasks < $maxTasksPerRun) {
        $task = $queue->fetchNextAndLock();
        if ($task === null) {
            break; // Очередь пуста
        }

        $taskId = (int)$task['ID'];
        $startTime = microtime(true);

        try {
            $payload = json_decode((string)$task['UF_PAYLOAD'], true, 3, JSON_THROW_ON_ERROR);
            $updatedCount = $updater->processBatch($payload);

            $queue->updateStatus($taskId, 'done');
            $duration = round(microtime(true) - $startTime, 3);

            $logger->info(sprintf(
                'Task #%d completed in %ss. Total records: %d, updated: %d',
                $taskId,
                $duration,
                count($payload),
                $updatedCount
            ));
        } catch (Throwable $e) {
            $queue->updateStatus($taskId, 'error', $e->getMessage());
            $logger->error(sprintf('Task #%d failed: %s', $taskId, $e->getMessage()));
        }

        $processedTasks++;
    }

    // Раз в сутки (в 03:00) очистка старых логов
    if (date('H:i') === '03:00') {
        $logBaseDir = dirname($_SERVER['DOCUMENT_ROOT']) . '/logs/division_sync';
        Logger::rotateLogs($logBaseDir, 30);
    }

} catch (Throwable $e) {
    $logger->error('Worker fatal error: ' . $e->getMessage());
} finally {
    $logger->flush();
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
