<?php

/**
 * (C) 2019 ООО "Напоправку.ру"
 * Date: 18.04.19
 * Time: 16:11
 */

namespace Napopravku\SuperDB\Services\Sync;

class SyncService
{
    public const IBLOCK_ELEMENT = 'iblock_element';
    public const IBLOCK_SECTION = 'iblock_section';
    public const FLAT_TABLE     = 'flat_table';

    private const SYNCER        = 'syncer';
    private const COMMON_SYNCER = 'CommonSyncer';

    /** @var array $syncConfig */
    private $syncConfig;

    /** @var string $connectionName */
    private $connectionName;

    /** @var array $connectionName */
    public $loadedSyncers = [];

    /**
     * SyncService constructor.
     */
    public function __construct()
    {
        $path = realpath(__DIR__ . '/../../../database/sync.json');

        $this->syncConfig     = json_decode(file_get_contents($path), true);
        $this->connectionName = $this->syncConfig['connection_name'];
    }

    public function checkImplementation(string $type, string $subType)
    {
        return isset($this->syncConfig[$type][$subType][self::SYNCER]);
    }

    public function getAdditionalProps(string $type, string $subType): array
    {
        return $this->syncConfig[$type][$subType]['additional_props'] ?? [];
    }

    public function getAdditionalConstraits(string $type, string $subType): array
    {
        return $this->syncConfig[$type][$subType]['additional_constraits'] ?? [];
    }

    public function getAdditionalTableConstraits(string $type, string $subType): array
    {
        return $this->syncConfig[$type][$subType]['additional_constrait_table'] ?? [];
    }

    public function getQueries(string $type, string $subType): array
    {
        return $this->syncConfig[$type][$subType]['queries'] ?? [];
    }

    public function getRedisCommands(string $type, string $subType): array
    {
        return $this->syncConfig[$type][$subType]['redis'] ?? [];
    }

    public function getConstraintSyncs(string $type, string $subType): array
    {
        $result          = [];
        $fields          = $this->syncConfig[$type][$subType]['fields'] ?? [];
        $multiFields     = $this->syncConfig[$type][$subType]['multi_fields'] ?? [];
        $constraitTables = $this->syncConfig[$type][$subType]['additional_constrait_table'] ?? [];

        foreach ($fields as $fieldName => $fieldConfig) {
            if (isset($fieldConfig['function'])) {
                switch ($fieldConfig['function']) {
                    case 'checkIBlockReferenceValue':
                        $result[] = [
                            'type'        => self::IBLOCK_ELEMENT,
                            'subType'     => $fieldConfig['args']['iblock_id'],
                            'sourceField' => $fieldConfig['args']['source_field'],
                        ];
                        break;

                    case 'checkTableReferenceValue':
                        $result[] = [
                            'type'        => self::FLAT_TABLE,
                            'subType'     => $fieldConfig['args']['source_table'],
                            'sourceField' => $fieldConfig['args']['source_field'],
                        ];
                        break;
                }
            }
        }

        foreach ($constraitTables as $constraitTableConfig) {
            $result[] = [
                'type'        => self::FLAT_TABLE,
                'subType'     => $constraitTableConfig['source_table'],
                'sourceField' => $constraitTableConfig['source_field'],
            ];
        }

        foreach ($multiFields as $fieldName => $fieldConfig) {
            if (isset($fieldConfig['iblock_id'])) {
                $result[] = [
                    'type'        => self::IBLOCK_ELEMENT,
                    'subType'     => $fieldConfig['iblock_id'],
                    'sourceField' => $fieldConfig['source_field'],
                ];
            } elseif ($fieldConfig['source_table']) {
                $result[] = [
                    'type'        => self::FLAT_TABLE,
                    'subType'     => $fieldConfig['source_table'],
                    'sourceField' => $fieldConfig['source_field'],
                ];
            }
        }

        return $result;
    }

    public function sync(string $type, string $subType, int $id, array $entity)
    {
        if ($this->checkImplementation($type, $subType)) {
            $syncSchema = $this->syncConfig[$type][$subType];

            $syncer = $this->getSyncer($syncSchema[self::SYNCER]);
            $syncer->sync($id, $entity, $syncSchema);
        }
    }

    public function unsync(string $type, string $subType, int $id)
    {
        if ($this->checkImplementation($type, $subType)) {
            $syncSchema = $this->syncConfig[$type][$subType];

            $syncer = $this->getSyncer(self::COMMON_SYNCER);
            $syncer->unsync($id, $syncSchema);
        }
    }

    private function getSyncer($alias): SyncerInterface
    {
        if (!array_key_exists($alias, $this->loadedSyncers)) {
            $syncClass = __NAMESPACE__ . '\\' . $alias;

            $this->loadedSyncers[$alias] = new $syncClass($this->connectionName);
        }

        return $this->loadedSyncers[$alias];
    }
}
