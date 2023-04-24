<?php

namespace DataWarehouse\Data;

use Configuration\XdmodConfiguration;
use CCR\DB;
use ETL\VariableStore;
use Exception;

/**
 * Provides access to `rawstatistics` configuration data.
 */
class RawStatisticsConfiguration
{
    /**
     * Singleton instance.
     * @var \DataWarehouse\Data\RawStatisticsConfiguration
     */
    private static $instance;

    /**
     * Raw statistics configuration parsed as array of arrays.
     * @var array[]
     */
    private $config;

    /**
     * Variable store for substitutions in raw statistics configuration files.
     * @var \ETL\VariableStore
     */
    private $variableStore;

    /**
     * Factory method.
     *
     * @return \DataWarehouse\Data\RawStatisticsConfiguration
     */
    public static function factory()
    {
        if (!isset(static::$instance)) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * Load the `rawstatistics` configuration files.
     */
    private function __construct()
    {
        $this->config = XdmodConfiguration::assocArrayFactory(
            'rawstatistics.json',
            CONFIG_DIR
        );

        $constantNames = [
            'HIERARCHY_BOTTOM_LEVEL_INFO',
            'HIERARCHY_BOTTOM_LEVEL_LABEL',
            'HIERARCHY_MIDDLE_LEVEL_INFO',
            'HIERARCHY_MIDDLE_LEVEL_LABEL',
            'HIERARCHY_TOP_LEVEL_INFO',
            'HIERARCHY_TOP_LEVEL_LABEL',
            'ORGANIZATION_NAME',
            'ORGANIZATION_NAME_ABBREV'
        ];
        $variableMap = [];
        foreach ($constantNames as $constantName) {
            $variableMap[$constantName] = constant($constantName);
        }
        $this->variableStore = new VariableStore($variableMap);
    }

    /**
     * Get the entire raw statistics configuration for a realm.
     *
     * @param string $realm The name of a realm.
     * @return array[]
     */
    private function getRealmConfiguration($realm)
    {
        if (!array_key_exists($realm, $this->config)) {
            throw new Exception(sprintf(
                'Raw Statistics configuration not found for realm "%s"',
                $realm
            ));
        }
        return $this->config[$realm];
    }

    /**
     * Get the configuration for all realms that support "show raw data".
     *
     * @return array[]
     */
    public function getRawDataRealms()
    {
        if (!array_key_exists('realms', $this->config)) {
            return [];
        }

        // The elements returned from array filter preserve their keys. This
        // may cause gaps since the keys are numeric. array_keys is used below
        // to reindex the keys
        $rawDataRealms =  array_filter(
            $this->config['realms'],
            function ($realm) {
                // If the "raw_data" key doesn't exist the realm is assumed to
                // support "show raw data".
                return !(array_key_exists('raw_data', $realm)
                    && $realm['raw_data'] === false);
            }
        );

        return array_values($rawDataRealms);
    }

    /**
     * Get the configuration for all realms that support "batch export".
     *
     * @return array[]
     */
    public function getBatchExportRealms()
    {
        if (!array_key_exists('realms', $this->config)) {
            return [];
        }

        // Every realm that has a `rawstatistics` configuration and is not disabled is
        // batch exportable.
        $realms = array_filter(
            $this->config['realms'],
            function ($realm) {
                return !(array_key_exists('export_enabled', $realm) && $realm['export_enabled'] === false);
            }
        );

        // Use array_values to remove gaps in keys that may have been
        // introduced by the use of array_filter.
        return array_values($realms);
    }

    /**
     * Get the table definitions for generating a query.
     *
     * @param string $realm The name of a realm.
     * @return array[]
     */
    public function getQueryTableDefinitions($realm)
    {
        $realmConfig = $this->getRealmConfiguration($realm);
        if (!array_key_exists('tables', $realmConfig)) {
            throw new Exception(sprintf(
                'Table definitions not found for realm "%s"',
                $realm
            ));
        }
        return $realmConfig['tables'];
    }

    /**
     * Get the field definitions for generating a query.
     *
     * @param string $realm The name of a realm.
     * @return array[]
     */
    public function getQueryFieldDefinitions($realm)
    {
        $realmConfig = $this->getRealmConfiguration($realm);

        if (!array_key_exists('fields', $realmConfig)) {
            throw new Exception(sprintf(
                'Field definitions not found for realm "%s"',
                $realm
            ));
        }

        $vs = $this->variableStore;
        return array_map(
            function ($field) use ($vs) {
                foreach (['name', 'documentation', 'alias'] as $key) {
                    if (array_key_exists($key, $field)) {
                        $field[$key] = $vs->substitute($field[$key]);
                    }
                }
                return $field;
            },
            $realmConfig['fields']
        );
    }

    /**
     * Get batch export field definitions.
     *
     * @param string $realm The name of a realm.
     * @param bool $includeDataTypes Whether to include the data types of
     *                               fields in the response.
     * @return array[]
     */
    public function getBatchExportFieldDefinitions(
        $realm,
        $includeDataTypes = null
    ) {
        $fields = [];

        if ($includeDataTypes) {
            $dataTypes = $this->getDataTypesOfAllColumnsInTables($realm);
        }

        foreach ($this->getQueryFieldDefinitions($realm) as $field) {
            // Skip "ignore" and "analysis" dtype
            if (isset($field['dtype']) && in_array($field['dtype'], ['ignore', 'analysis'])) {
                continue;
            }

            $export = isset($field['batchExport']) ? $field['batchExport'] : false;
            if ($export === false) {
                continue;
            }
            if (!in_array($export, [true, 'anonymize'], true)) {
                throw new Exception(sprintf(
                    'Unknown "batchExport" option %s',
                    var_export($export, true)
                ));
            }

            $display = $field['name'];
            if (isset($field['units']) && $field['units'] === 'ts') {
                $display .= ' (Timestamp)';
            }
            if ($export === 'anonymize') {
                $display .= ' (Deidentified)';
            }

            $fieldDefinition = [
                'name' => $field['name'],
                'alias' => isset($field['alias']) ? $field['alias'] : $field['name'],
                'display' => $display,
                'anonymize' => ($export === 'anonymize'),
                'documentation' => $field['documentation']
            ];

            if ($includeDataTypes) {
                $fieldDefinition['dataType'] = (
                    isset($field['formula'])
                    ? $field['type']
                    : $dataTypes[$field['tableAlias']][$field['column']]
                );
            }

            $fields[] = $fieldDefinition;
        }

        return $fields;
    }

    private function getDataTypesOfAllColumnsInTables($realm)
    {
        $dataTypes = [];
        $tables = $this->getQueryTableDefinitions($realm);
        foreach ($tables as $table) {
            $dataTypes[$table['alias']] =
                self::getDataTypesOfAllColumnsInTable($table);
        }
        return $dataTypes;
    }

    private static function getDataTypesOfAllColumnsInTable($table)
    {
        $dataTypes = [];
        $db = DB::factory('datawarehouse');
        $results = $db->query(
            'SELECT column_name, column_type'
            . " FROM information_schema.columns"
            . ' WHERE table_schema = :table_schema'
            . ' AND table_name = :table_name',
            [
                ':table_schema' => $table['schema'],
                ':table_name' => $table['name']
            ]
        );
        foreach ($results as $result) {
            $dataTypes[$result['column_name']] = $result['column_type'];
        }
        return $dataTypes;
    }
}
