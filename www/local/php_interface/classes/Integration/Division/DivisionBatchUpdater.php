<?php

declare(strict_types=1);

namespace Project\Integration\Division;

use Bitrix\Main\Application;
use Bitrix\Main\UserTable;
use CUser;
use Throwable;

final class DivisionBatchUpdater
{
    private const CHUNK_SIZE = 500;
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<string, string> $userDivisionMap Массив вида ['login' => 'division_code']
     * @param bool $useBitrixApi Использовать штатный CUser::Update вместо прямого SQL
     */
    public function processBatch(array $userDivisionMap, bool $useBitrixApi = false): int
    {
        if (empty($userDivisionMap)) {
            return 0;
        }

        $totalUpdated = 0;
        $chunks = array_chunk($userDivisionMap, self::CHUNK_SIZE, true);

        foreach ($chunks as $chunkIndex => $chunk) {
            $updatedInChunk = $this->processChunk($chunk, $useBitrixApi);
            $totalUpdated += $updatedInChunk;
            $this->logger->info(sprintf('Processed chunk #%d: updated %d users', $chunkIndex + 1, $updatedInChunk));
        }

        return $totalUpdated;
    }

    /**
     * @param array<string, string> $chunk
     */
    private function processChunk(array $chunk, bool $useBitrixApi): int
    {
        $userRows = UserTable::getList([
            'select' => ['ID', 'LOGIN', 'UF_CODE'],
            'filter' => ['@LOGIN' => array_keys($chunk)],
        ])->fetchAll();

        if (empty($userRows)) {
            return 0;
        }

        $toUpdate = [];
        foreach ($userRows as $row) {
            $login = (string)$row['LOGIN'];
            $newCode = (string)($chunk[$login] ?? '');
            $currentCode = (string)($row['UF_CODE'] ?? '');

            if ($newCode !== '' && $newCode !== $currentCode) {
                $toUpdate[(int)$row['ID']] = $newCode;
            }
        }

        if (empty($toUpdate)) {
            return 0;
        }

        if ($useBitrixApi) {
            return $this->updateViaBitrixApi($toUpdate);
        }

        $this->bulkUpdateUserFields($toUpdate);

        if (defined('BX_COMP_MANAGED_CACHE')) {
            Application::getInstance()->getTaggedCache()->clearByTag('USER_CARD');
        }

        return count($toUpdate);
    }

    /**
     * Вариант 1 (основной): массовый SQL UPDATE в b_uts_user за 1 запрос на чанк.
     * Максимальная производительность, не вызывает события ядра.
     *
     * @param array<int, string> $idToCodeMap [USER_ID => UF_CODE]
     */
    private function bulkUpdateUserFields(array $idToCodeMap): void
    {
        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();

        $cases = [];
        $ids = [];

        foreach ($idToCodeMap as $userId => $code) {
            $safeId = (int)$userId;
            $safeCode = $sqlHelper->forSql($code);
            $cases[] = sprintf("WHEN %d THEN '%s'", $safeId, $safeCode);
            $ids[] = $safeId;
        }

        $sql = sprintf(
            'UPDATE b_uts_user SET UF_CODE = CASE VALUE_ID %s END WHERE VALUE_ID IN (%s)',
            implode(' ', $cases),
            implode(', ', $ids)
        );

        $connection->startTransaction();
        try {
            $connection->queryExecute($sql);
            $connection->commitTransaction();
        } catch (Throwable $e) {
            $connection->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Вариант 2 (альтернативный): обновление через штатный API Bitrix (CUser::Update).
     * Медленнее прямого SQL, но сохраняет вызов событий (OnAfterUserUpdate) и логику ядра.
     *
     * @param array<int, string> $idToCodeMap [USER_ID => UF_CODE]
     */
    private function updateViaBitrixApi(array $idToCodeMap): int
    {
        $user = new CUser();
        $updatedCount = 0;

        foreach ($idToCodeMap as $userId => $code) {
            // Обновляем только изменённые записи и только одно поле
            if ($user->Update($userId, ['UF_CODE' => $code])) {
                $updatedCount++;
            } else {
                $this->logger->error(sprintf('Failed to update user #%d: %s', $userId, $user->LAST_ERROR));
            }
        }

        return $updatedCount;
    }
}
