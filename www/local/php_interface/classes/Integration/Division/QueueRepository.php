<?php

declare(strict_types=1);

namespace Project\Integration\Division;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;
use RuntimeException;

final class QueueRepository
{
    private const HL_BLOCK_NAME = 'DivisionImportQueue';
    /** @var class-string<DataManager> */
    private string $entityDataClass;

    public function __construct()
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new RuntimeException('Module highloadblock is not installed');
        }

        $hlBlock = HighloadBlockTable::getList([
            'filter' => ['=NAME' => self::HL_BLOCK_NAME],
            'cache'  => ['ttl' => 86400],
        ])->fetch();

        if (!$hlBlock) {
            throw new RuntimeException(sprintf('Highload-block "%s" not found', self::HL_BLOCK_NAME));
        }

        $entity = HighloadBlockTable::compileEntity($hlBlock);
        $this->entityDataClass = $entity->getDataClass();
    }

    /**
     * Сохраняет валидированный пакет в очередь за 1 INSERT
     */
    public function push(array $payload): int
    {
        $result = $this->entityDataClass::add([
            'UF_STATUS'     => 'new',
            'UF_PAYLOAD'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'UF_CREATED_AT' => new DateTime(),
        ]);

        if (!$result->isSuccess()) {
            throw new RuntimeException('Failed to push batch to queue: ' . implode(', ', $result->getErrorMessages()));
        }

        return (int)$result->getId();
    }

    /**
     * Забирает следующую задачу со статусом 'new' и блокирует её статусом 'processing'
     */
    public function fetchNextAndLock(): ?array
    {
        $item = $this->entityDataClass::getList([
            'select' => ['ID', 'UF_PAYLOAD'],
            'filter' => ['=UF_STATUS' => 'new'],
            'order'  => ['ID' => 'ASC'],
            'limit'  => 1,
        ])->fetch();

        if (!$item) {
            return null;
        }

        $this->updateStatus((int)$item['ID'], 'processing');

        return $item;
    }

    public function updateStatus(int $id, string $status, string $errorMessage = ''): void
    {
        $fields = ['UF_STATUS' => $status];
        if ($errorMessage !== '') {
            $fields['UF_ERROR_MSG'] = mb_substr($errorMessage, 0, 1000);
        }

        $this->entityDataClass::update($id, $fields);
    }
}
