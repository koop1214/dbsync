<?php

/**
 * (C) 2019 i.antushevich
 * Date: 19.04.19
 * Time: 8:25
 */

namespace Np\DBTools\Services\Sync;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Arr;

class CommonSyncer implements SyncerInterface
{
    /** @var string $connectionName */
    protected $connectionName;

    /**
     * CommonSync constructor.
     *
     * @param string $connectionName
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }

    /**
     * @param int   $id
     * @param array $syncSchema
     */
    public function unsync(int $id, array $syncSchema)
    {
        $tableName = $syncSchema['table'];

        $foreign_key = $syncSchema['foreign_key'] ?: 'id';
        Capsule::table($tableName, $this->connectionName)
            ->where($foreign_key, '=', $id)
            ->delete();
    }

    /**
     * @param int   $id
     * @param array $entity
     * @param array $syncSchema
     */
    public function sync(int $id, array $entity, array $syncSchema)
    {
        if (!$this->needSync($entity)) {
            return;
        }

        $tableName = $syncSchema['table'];
        $foreign_key = $syncSchema['foreign_key'] ?: 'id';
        $fields    = $this->prepareFields($entity, $syncSchema);

        if (isset($syncSchema['source_field'])) {
            $id = $entity[$syncSchema['source_field']];
        } elseif (isset($syncSchema['foreign_key_field_value'])) {
            $id = $fields[$syncSchema['foreign_key_field_value']];
        }

        $attributes = [$foreign_key => $id];

        if (isset($syncSchema['where_function'])) {
            $method     = $syncSchema['where_function'];
            $attributes = $this->$method($id, $entity, $fields);
        } elseif (isset($syncSchema['where_only'])) {
            $attributes = Arr::only($fields, $syncSchema['where_only']);
        }

        Capsule::table($tableName, $this->connectionName)->updateOrInsert($attributes, $fields);

        if (isset($syncSchema['additional_tables'])) {
            foreach ($syncSchema['additional_tables'] as $additionalSchema) {
                if (!$this->needSyncAdditionalTable($entity, $additionalSchema)) {
                    continue;
                }

                $tableName  = $additionalSchema['table'];
                $foreignKey = $additionalSchema['foreign_key'] ?: 'id';
                $where      = $additionalSchema['where'] ?? [];
                $fields     = $this->prepareFields($entity, $additionalSchema);

                Capsule::table($tableName, $this->connectionName)->updateOrInsert([$foreignKey => $id] + $where, $fields);
            }
        }

        if (isset($syncSchema['multi_fields'])) {
            foreach ($syncSchema['multi_fields'] as $fieldConfig) {
                if (isset($fieldConfig['function'])) {
                    $method = $fieldConfig['function'];
                    $this->$method($id, $entity, $fieldConfig);
                } else {
                    $this->processMultiField($id, $entity, $fieldConfig);
                }
            }
        }
    }

    protected function needSync(array $entity): bool
    {
        return true;
    }

    protected function needSyncAdditionalTable(array $entity, array $additionalSchema): bool
    {
        return true;
    }

    /**
     * @param array $entity
     * @param array $syncSchema
     *
     * @return array
     */
    protected function prepareFields(array $entity, array $syncSchema): array
    {
        $fields = [];

        foreach ($syncSchema['fields'] as $fieldName => $fieldConfig) {
            if (isset($fieldConfig['function'])) {
                $method = $fieldConfig['function'];

                if (isset($fieldConfig['args'])) {
                    $fields[$fieldName] = $this->$method($fieldConfig['args'], $entity);
                } else {
                    $fields[$fieldName] = $this->$method($entity);
                }
            } else {
                $sourceField        = $fieldConfig['source_field'];
                $fields[$fieldName] = $entity[$sourceField];
            }

            if (array_key_exists('default', $fieldConfig) && empty($fields[$fieldName])) {
                $fields[$fieldName] = $fieldConfig['default'];
            }
        }

        return $fields;
    }

    /**
     * @param array $entity
     *
     * @return bool
     */
    protected function isActive(array $entity): bool
    {
        return $entity['ACTIVE'] === 'Y';
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return mixed|null
     */
    protected function getAny(array $args, array $entity)
    {
        foreach ($args['source_fields'] as $sourceField) {
            if (isset($entity[$sourceField]) && !empty($entity[$sourceField])) {
                return $entity[$sourceField];
            }
        }

        return null;
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return mixed|null
     */
    protected function getFirst(array $args, array $entity)
    {
        $sourceField = $args['source_field'];

        return reset($entity[$sourceField]);
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return mixed|null
     */
    protected function getUserId(array $args, array $entity)
    {
        $sourceField = $args['source_field'];
        $userId      = $entity[$sourceField];
        $userId      = is_array($userId) ? reset($userId) : $userId;
        $isExists    = false;

        if ($userId) {
            $isExists = Capsule::table('users', $this->connectionName)->where('id', $userId)->exists();
        }

        return $isExists ? $userId : null;
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return bool
     */
    protected function getBoolean(array $args, array $entity): bool
    {
        $sourceField = $args['source_field'];

        return isset($entity[$sourceField]);
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return bool
     */
    protected function getReverseBoolean(array $args, array $entity): bool
    {
        return !$this->getBoolean($args, $entity);
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return int
     */
    protected function getInt(array $args, array $entity): int
    {
        $sourceField = $args['source_field'];

        return (int)$entity[$sourceField];
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return int
     */
    protected function getIntOrNull(array $args, array $entity): ?int
    {
        $sourceField = $args['source_field'];
        $value       = $entity[$sourceField];

        return $value === null ? null : (int)$value;
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return int
     */
    protected function getIntRange(array $args, array $entity): int
    {
        $sourceField = $args['source_field'];
        $max         = $args['max'];
        $min         = $args['min'];
        $value       = (int)$entity[$sourceField];

        return max(min($value, $max), $min);
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return float
     */
    protected function getFloatRange(array $args, array $entity): float
    {
        $sourceField = $args['source_field'];
        $max         = $args['max'];
        $min         = $args['min'];
        $value       = (float)$entity[$sourceField];

        return max(min($value, $max), $min);
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return string
     */
    protected function getString(array $args, array $entity): string
    {
        $sourceField = $args['source_field'];

        return (string)$entity[$sourceField];
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return string
     */
    protected function getLowerString(array $args, array $entity): string
    {
        $sourceField = $args['source_field'];
        $text        = (string)$entity[$sourceField];

        return mb_strtolower($text);
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return string|null
     */
    protected function getCoordPoint(array $args, array $entity): ?string
    {
        $result = [];

        $sourceField   = $args['source_field'];
        $delimiter     = $args['delimiter'];
        $value         = (string)$entity[$sourceField];
        $parts         = explode($delimiter, $value);
        $isNormalOrder = strpos($value, ',') !== false;

        if ($parts && count($parts) === 2) {
            $result = ['lat' => (float)$parts[$isNormalOrder ? 0 : 1], 'lon' => (float)$parts[$isNormalOrder ? 1 : 0]];
        }

        return $result ? $this->formatJson($result) : null;
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return string
     */
    protected function getJson(array $args, array $entity): string
    {
        $sourceField = $args['source_field'];

        return $this->formatJson((array)$entity[$sourceField]);
    }

    protected function getCommaJson(array $args, array $entity): string
    {
        $processFunction = $args['process_function'] ?? 'trim';
        $sourceField     = $args['source_field'];
        $value           = (string)$entity[$sourceField];
        $values          = explode(',', $value ?: '');
        $values          = array_filter(array_map(function ($single) use ($processFunction) {
            return $processFunction($single);
        }, $values));

        return $this->formatJson(array_values($values));
    }

    protected function formatJson(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return string
     */
    protected function combineToJsonArray(array $args, array $entity): string
    {
        $fieldNames = $args['fields'];
        $fields     = array_only($entity, $fieldNames);
        $fields     = array_filter(array_flatten($fields));

        return $this->formatJson(array_values($fields));
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return null|Carbon
     */
    protected function getTimestamp(array $args, array $entity): ?Carbon
    {
        $format      = $args['format'] ?? null;
        $sourceField = $args['source_field'];
        $rawDate     = $entity[$sourceField];

        if ($format) {
            return Carbon::createFromFormat($format, $rawDate);
        } elseif (strlen($rawDate) === 10) {
            return Carbon::createFromFormat('d.m.Y', $rawDate);
        } elseif (strlen($rawDate) === 19) {
            return Carbon::createFromFormat('d.m.Y H:i:s', $rawDate);
        }

        return null;
    }

    /**
     * @param array $args
     * @param array $entity
     *
     * @return string|null
     */
    protected function getEnum(array $args, array $entity): ?string
    {
        $sourceField = $args['source_field'];
        $value       = $entity[$sourceField];
        $newValue    = $args['replaces'][$value] ?? $value;

        if (empty($newValue) && array_key_exists('default', $args)) {
            $newValue = $args['default'];
        }

        return $newValue;
    }

    protected function processMultiField(int $id, array $entity, array $fieldConfig)
    {
        $pivotTable  = $fieldConfig['pivot_table'];
        $otherTable  = $fieldConfig['other_table'] ?? '';
        $foreignKey  = $fieldConfig['foreign_key'];
        $otherKey    = $fieldConfig['other_key'];
        $sourceField = $fieldConfig['source_field'];
        $iBlockId    = (array)($fieldConfig['iblock_id'] ?? null);
        $iReferences = $entity['REFERENCES'];
        $tReferences = $entity['TABLES'] ?? [];
        $extraData   = $entity['EXTRA_DATA'][$pivotTable] ?? [];

        if (is_array($sourceField)) {
            $otherIds = array_unique(array_filter(array_map('intval', array_flatten(array_only($entity, $sourceField)))));
        } else {
            $otherIds = array_unique((array)$entity[$sourceField]);
        }

        Capsule::table($pivotTable, $this->connectionName)->where($foreignKey, $id)->delete();

        foreach ($otherIds as $otherId) {
            if ($iBlockId && (!isset($iReferences[$otherId]) || !in_array($iReferences[$otherId], $iBlockId))) {
                continue;
            }

            if ($otherTable && !isset($tReferences[$otherTable][$otherId])) {
                continue;
            }

            $values = [$foreignKey => $id, $otherKey => $otherId];

            if ($extraData) {
                $extraDatum = $extraData[$otherId] ?? [];
                $values     += $extraDatum;
            }

            Capsule::table($pivotTable, $this->connectionName)->insert($values);
        }
    }

    protected function getTextFromTextProp(array $args, array $entity)
    {
        if (is_array($entity[$args['source_field']])) {
            return trim($entity[$args['source_field']]['TEXT']) ?: '';
        } else {
            return trim($entity[$args['source_field']]) ?: '';
        }
    }

    protected function getTrimmedText(array $args, array $entity)
    {
        return trim($entity[$args['source_field']]) ?: '';
    }

    protected function checkIBlockReferenceValue(array $args, array $entity)
    {
        $sourceField = $args['source_field'];

        if (!empty($entity[$sourceField])) {
            $realIBlockId = (int)($entity['REFERENCES'][$entity[$sourceField]] ?? 0);
            $needIBlockId = (int)$args['iblock_id'];

            if ($needIBlockId === $realIBlockId) {
                return $entity[$args['source_field']];
            }
        }

        return null;
    }

    protected function checkTableReferenceValue(array $args, array $entity)
    {
        $sourceField = $args['source_field'];

        if (!empty($entity[$sourceField])) {
            $table = $args['foreign_table'];
            $value = $entity[$sourceField];

            if (isset($entity['TABLES'][$table][$value])) {
                return $value;
            } elseif (is_array($value) && count($value) === 1) {
                return $value[0];
            }
        }

        return null;
    }

    protected function getCityId(array $entity): ?int
    {
        $cityId = (int)($entity['SECTION']['UF_CITY_ID'] ?? 0);

        return $cityId ?: null;
    }
}
