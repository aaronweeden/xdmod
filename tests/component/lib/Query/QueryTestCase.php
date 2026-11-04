<?php

namespace ComponentTests\Query;

use CCR\Log as Logger;
use PHPUnit\Framework\TestCase;
use Realm\Realm;

abstract class QueryTestCase extends TestCase
{
    private const SQL_DEFS = [
        'gb_select_fields' => [
            'person' => [
                'person.id',
                'person.short_name',
                'person.long_name',
                'person.order_id'
            ],
            'username' => [
                'systemaccount.username',
                'systemaccount.username',
                'systemaccount.username',
                'systemaccount.username'
            ]
        ],
        'stat_select_fields' => [
            'running_job_count' => 'COALESCE(SUM(CASE duration.id WHEN %s THEN agg.running_job_count ELSE agg.started_job_count END), 0)',
            'job_count' => 'COALESCE(SUM(agg.ended_job_count), 0)'
        ],
        'join_tables' => [
            'person' => 'modw.person person',
            'username' => 'modw.systemaccount systemaccount'
        ],
        'join_wheres' => [
            'person' => 'person.id = agg.person_id',
            'username' => 'systemaccount.id = agg.systemaccount_id'
        ],
        'order_bys' => [
            'person' => 'person.order_id',
            'username' => 'systemaccount.username'
        ]
    ];

    protected static $QUERY_CLASS;

    protected static $logger = null;

    public static function setupBeforeClass(): void
    {
        // Set up a logger so we can get warnings and error messages

        $conf = array(
            'file' => false,
            'db' => false,
            'mail' => false,
            'consoleLogLevel' => Logger::EMERG
        );
        self::$logger = Logger::factory('PHPUnit', $conf);

        // In order to use a non-standard location for datawarehouse.json we must manually
        // initialize the Realm class.

        $options = (object) array(
            'config_file_name' => 'datawarehouse.json',
            'config_base_dir'  => realpath('../artifacts/xdmod/realm')
        );

        Realm::initialize(self::$logger, $options);
    }

    protected function runQueryTest($options = []): void
    {
        $defaults = [
            'start_date' => '2016-12-01',
            'start_day_id' => 201600357,
            'end_date' => '2017-01-31',
            'end_day_id' => 201700001,
            'realm' => 'Jobs',
            'statistic' => null,
            'group_by' => null,
            'aggregation_unit' => 'day',
            'add_group_by' => null,
            'add_stat' => null
        ];
        $options = array_replace($defaults, $options);
        $query = new static::$QUERY_CLASS(
            $options['realm'],
            $options['aggregation_unit'],
            $options['start_date'],
            $options['end_date'],
            $options['group_by'],
            $options['statistic']
        );
        if (null !== $options['add_group_by']) {
            $query->addGroupBy($options['add_group_by']);
        }
        if (null !== $options['add_stat']) {
            $query->addStat($options['add_stat']);
        }
        $actual = $query->getQueryString();
        $selectFieldsSql = "\n";
        $joinTableSql = '';
        $joinWhereSql = "\n";
        $groupBySql = '';
        $orderBySql = '';
        // Construct SQL for the group bys.
        foreach (['group_by', 'add_group_by'] as $option) {
            if (null !== $options[$option]) {
                $gbSelectFields = self::SQL_DEFS['gb_select_fields'][$options[$option]];
                $selectFieldsSql .= <<<END
  {$gbSelectFields[0]} AS '{$options[$option]}_id',
  {$gbSelectFields[1]} AS '{$options[$option]}_short_name',
  {$gbSelectFields[2]} AS '{$options[$option]}_name',
  {$gbSelectFields[3]} AS '{$options[$option]}_order_id'
END;
                $joinTableSql .= ",\n  " . self::SQL_DEFS['join_tables'][$options[$option]];
                $joinWhereSql = "\n  AND " . self::SQL_DEFS['join_wheres'][$options[$option]];
                $groupBySql = "\nGROUP BY\n  " . implode(
                    ",\n  ",
                    array_unique(self::SQL_DEFS['gb_select_fields'][$options[$option]])
                );
                $orderBySql = "\nORDER BY\n  " . self::SQL_DEFS['order_bys'][$options[$option]] . ' ASC';
            }
        }
        // Construct SQL for the statistics.
        if (null !== $options['statistic'] || null !== $options['add_stat']) {
            if (null !== $options['group_by'] || null !== $options['add_group_by']) {
                $selectFieldsSql .= ",\n";
            }
            $statSelectFields = [];
            // Defining a statistic in the Query constructor means all statistics should be added
            // to the query.
            if (null !== $options['statistic']) {
                foreach (self::SQL_DEFS['stat_select_fields'] as $stat => $selectField) {
                    $statSelectFieldSql = "$selectField AS $stat";
                    // The running_job_count statistic includes the start day ID.
                    if ('running_job_count' === $stat) {
                        $statSelectFieldSql = sprintf($statSelectFieldSql, $options['start_day_id']);
                    }
                    $statSelectFields[] = $statSelectFieldSql;
                }
            }
            if (null !== $options['add_stat']) {
                $statSelectFields = [
                    self::SQL_DEFS['stat_select_fields'][$options['add_stat']]
                    . ' AS '
                    . $options['add_stat']
                ];
            } else {
            }
            $selectFieldsSql .= '  ' . implode(",\n  ", $statSelectFields);
        }
        $expected  =<<<SQL
SELECT$selectFieldsSql
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration$joinTableSql
WHERE
  duration.id = agg.day_id
  AND agg.day_id between {$options['start_day_id']} and {$options['end_day_id']}$joinWhereSql$groupBySql$orderBySql
SQL;
        $this->assertEquals($expected, $actual);
    }
}
