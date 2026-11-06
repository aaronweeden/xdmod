<?php
/**
 * This various aspects of the Query class as well as parts of the Realm, GroupBy, and Statistics
 * classes that require database access.
 */

namespace ComponentTests\Query;

use CCR\Log as Logger;
use DataWarehouse\Query\AggregateQuery;
use Psr\Log\LoggerInterface;

class AggregateQueryTest extends \PHPUnit\Framework\TestCase
{
    const START_DATE = '2016-12-01';
    const END_DATE = '2017-01-31';
    const START_DAY_ID = 201600357;
    const END_DAY_ID = 201700001;

    /**
     * Expected snippets of SQL that are reused in multiple tests
     */
     const EXPECTED_SQL = [
        'select' => [
            'none' => [
                "-9999 as 'none_id'",
                "'Screwdriver' as 'none_short_name'",
                "'Screwdriver' as 'none_name'",
                "'Screwdriver' as 'none_order_id'"
            ],
            'person' => [
                "person__person.id as 'person_id'",
                "person__person.short_name as 'person_short_name'",
                "person__person.long_name as 'person_name'",
                "person__person.order_id as 'person_order_id'"
            ],
            'username' => [
                "username__systemaccount.username as 'username_id'",
                "username__systemaccount.username as 'username_short_name'",
                "username__systemaccount.username as 'username_name'",
                "username__systemaccount.username as 'username_order_id'"
            ],
            'queue' => [
                "queue__queue.id as 'queue_id'",
                "queue__queue.id as 'queue_short_name'",
                "queue__queue.id as 'queue_name'",
                "queue__queue.id as 'queue_order_id'"
            ],
            'job_count' => ['COALESCE(SUM(agg.ended_job_count), 0) AS job_count'],
            'running_job_count' => ['COALESCE(SUM(CASE duration.id WHEN ' . self::START_DAY_ID . ' THEN agg.running_job_count ELSE agg.started_job_count END), 0) AS running_job_count']
        ],
        'from' => [
            'jobs_by_day' => [
                'modw_aggregates.jobfact_by_day agg',
                'modw.days duration'
            ],
            'person' => ['modw.person person__person'],
            'username' => ['modw.systemaccount username__systemaccount'],
            'queue' => ['modw.queue queue__queue']
        ],
        'where' => [
            'jobs_by_day' => [
                'duration.id = agg.day_id',
                'agg.day_id between 201600357 and 201700001'
            ],
            'person' => ['person__person.id = agg.person_id'],
            'username' => ['username__systemaccount.id = agg.systemaccount_id'],
            'queue' => [
                'queue__queue.id = agg.queue',
                'queue__queue.resource_id = agg.task_resource_id'
            ]
        ],
        'group_by' => [
            'person' => [
                'person__person.id',
                'person__person.short_name',
                'person__person.long_name',
                'person__person.order_id',
            ],
            'username' => ['username__systemaccount.username'],
            'queue' => ['queue__queue.id']
        ],
        'order_by' => [
            'person' => ['person__person.order_id ASC'],
            'username' => ['username__systemaccount.username ASC'],
            'queue' => ['queue__queue.id ASC']
        ]
    ];

    const ALL_STATS = ['job_count', 'running_job_count'];

    /**
     * @var LoggerInterface|null
     */
    protected static $logger = null;

    public static function setupBeforeClass(): void
    {
        // Set up a logger so we can get warnings and error messages

        $conf = array(
            'file' => false,
            'db' => false,
            'mail' => false,
            'consoleLogLevel' => Logger::WARNING
        );
        self::$logger = Logger::factory('PHPUnit', $conf);

        // In order to use a non-standard location for datawarehouse.json we must manually
        // initialize the Realm class.

        $options = (object) array(
            'config_base_dir'  => realpath('../artifacts/xdmod/realm'),
            'config_file_name' => 'datawarehouse.json'
        );

        \Realm\Realm::initialize(self::$logger, $options);
    }

    /**
     * Helper function for checking a query string matches the expected value
     *
     * @param $actual string the query string that is being tested
     * @param $options array 
     */
    private function runQueryStringTest(string $actual, array $options): void
    {
        // Fill in any unspecified options with defaults.
        $defaultOptions = [
            'group_bys' => [],
            'stats' => [],
            'additional_wheres' => [],
            'additional_order_bys' => [],
            'limit' => null,
            'offset' => null
        ];
        $options = array_merge($defaultOptions, $options);
        if (['all'] === $options['stats']) {
            $options['stats'] = self::ALL_STATS;
        }
        // Piece together the SQL clauses based on the specified $stats and/or $groupBys
        // and the predefined self::EXPECTED_SQL.
        $ids = array_merge(['jobs_by_day'], $options['group_bys'], $options['stats']);
        $sqlClauses = [];
        foreach (['select', 'from', 'where', 'group_by', 'order_by'] as $clause) {
            $sqlSnippetsArray = [];
            foreach ($ids as $id) {
                $sqlSnippetsArray = array_merge(
                    $sqlSnippetsArray,
                    self::EXPECTED_SQL[$clause][$id] ?? []
                );
            }
            if ('where' === $clause && !empty($options['additional_wheres'])) {
                $sqlSnippetsArray = array_merge(
                    $sqlSnippetsArray,
                    $options['additional_wheres']
                );
            }
            if ('order_by' === $clause && !empty($options['additional_order_bys'])) {
                $sqlSnippetsArray = array_merge(
                    $options['additional_order_bys'],
                    $sqlSnippetsArray
                );
            }
            $separator = ",\n  ";
            if ('where' === $clause) {
                $separator = "\n  AND ";
            }
            $sqlClauses[$clause] = implode($separator, $sqlSnippetsArray);
        }
        $sqlClauses['select'] = "SELECT\n  " . $sqlClauses['select'];
        $sqlClauses['from'] = "\nFROM\n  " . $sqlClauses['from'];
        $sqlClauses['where'] = "\nWHERE\n  " . $sqlClauses['where'] . "\n";
        if ('' !== $sqlClauses['group_by']) {
            $sqlClauses['group_by'] = "GROUP BY\n  " . $sqlClauses['group_by'];
        }
        if ('' !== $sqlClauses['order_by']) {
            $sqlClauses['order_by'] = "\nORDER BY\n  " . $sqlClauses['order_by'];
        }
        if (null !== $options['limit']) {
            $sqlClauses['limit'] = "\nLIMIT " . $options['limit'];
        }
        if (null !== $options['offset']) {
            $sqlClauses['offset'] = " OFFSET " . $options['offset'];
        }
        $expected = implode('', array_values($sqlClauses));
        $this->assertEquals($expected, $actual);
    }

    /**
     * Create an aggregate query with no group by or statistic. We will not be able to generate the
     * query string with no fields for the SELECT clause.
     *
     *
     */

    public function testAggregateQueryNoStatisticNoGroupBy()
    {
        $this->expectException(\Exception::class);
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE
        );
        $query->getQueryString();
    }

    /**
     * Create an aggregate query with a group by but no statistic. The select field will contain the
     * group by id, short name, and name.
     */

    public function testAggregateQueryNoStatistic()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'person'
        );
        $this->runQueryStringTest(
            $query->getQueryString(),
            ['group_bys' => ['person']]
        );
    }

    /**
     * Create an aggregate query with a group by but no statistic. The select field will contain the
     * group by id, short name, and name.
     */

    public function testAggregateQueryWithAlternateGroupByColumn()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'username'
        );
        $this->runQueryStringTest(
            $query->getQueryString(),
            ['group_bys' => ['username']]
        );
    }

    /**
     * Create an aggregate query with a group by and statistic. This adds all available statistics
     * to the query.
     */

    public function testAggregateQueryGroupByAndStatistic()
    {
        // Specifying the statistic in the constructor calls Query::setStat() which adds all
        // available statistics. Note that the order of the statistics in the query are dependent on
        // the oder specified in the config file.

        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'person',
            'job_count'
        );
        $this->runQueryStringTest(
            $query->getQueryString(),
            [
                'group_bys' => ['person'],
                'stats' => ['all']
            ]
        );
    }

    /**
     * Create an aggregate query with a statistic but no group by. This is essentially a group by None.
     */

    public function testAggregateQueryStatisticNoGroupBy()
    {
        // Specifying the statistic in the constructor calls Query::setStat() which adds all
        // available statistics. Note that the order of the statistics in the query are dependent on
        // the oder specified in the config file.

        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            null,
            'job_count'
        );
        $this->runQueryStringTest(
            $query->getQueryString(),
            ['stats' => ['all']]
        );
    }

    /**
     * Create an aggregate query and then add a group by and a statistic.
     */

    public function testAggregateQueryAddGroupByAndStatistic()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE
        );
        $query->addGroupBy('person');
        $query->addStat('job_count');
        $this->runQueryStringTest(
            $query->getQueryString(),
            [
                'group_bys' => ['person'],
                'stats' => ['job_count']
            ]
        );
    }

    /**
     * Create an aggregate query with a group by and then add a statistic.
     */

    public function testAggregateQueryWithGroupByAddStatistic()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'person'
        );
        $query->addStat('job_count');

        // addOrderByAndSetSortInfo() is called from ComplexDataset and TimeseriesChart and
        // prepends the metric to the ORDER BY clause. Simulate that here.

        $data_description = (object) array(
            'sort_type' => 'value_desc',
            'group_by'  => 'person',
            'metric'    => 'job_count'
        );
        $query->addOrderByAndSetSortInfo($data_description);
        $this->runQueryStringTest(
            $query->getQueryString(10, 0),
            [
                'group_bys' => ['person'],
                'stats' => ['job_count'],
                'additional_order_bys' => [
                    'job_count desc',
                ],
                'limit' => 10,
                'offset' => 0
            ]
        );
    }

    /**
     * Test adding an additional JOIN constraint to the query, as is used in GroupByQueue.
     */

    public function testAggregateQueryAdditionalJoinConstraint()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE
        );
        $query->addGroupBy('queue');
        $query->addStat('job_count');

        // addOrderByAndSetSortInfo() is called from ComplexDataset and TimeseriesChart and
        // prepends the metric to the ORDER BY clause. Simulate that here.

        $data_description = (object) array(
            'sort_type' => 'value_desc',
            'group_by'  => 'queue',
            'metric'    => 'job_count'
        );
        $query->addOrderByAndSetSortInfo($data_description);
        $this->runQueryStringTest(
            $query->getQueryString(),
            [
                'group_bys' => ['queue'],
                'stats' => ['job_count'],
                'additional_order_bys' => [
                    'job_count desc',
                ]
            ]
        );
    }

    /**
     * Test using group by none, which is a special case of aggregation unit group-by.
     */

    public function testAggregateQueryGroupByNone()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE
        );
        $query->addGroupBy('none');
        $query->addStat('job_count');

        // addOrderByAndSetSortInfo() is called from ComplexDataset and TimeseriesChart and
        // prepends the metric to the ORDER BY clause. Simulate that here.

        $data_description = (object) array(
            'sort_type' => 'value_desc',
            'group_by'  => 'none',
            'metric'    => 'job_count'
        );
        $query->addOrderByAndSetSortInfo($data_description);
        $this->runQueryStringTest(
            $query->getQueryString(),
            [
                'group_bys' => ['none'],
                'stats' => ['job_count'],
                'additional_order_bys' => [
                    'job_count desc',
                ]
            ]
        );
    }

    /**
     * Test the results of getQueryString() with the group by specified in the constructor and
     * separately by calling addGroupBy(). The results should be the same.
     */

    public function testAggregateQuerySpecifyGroupBy()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'person'
        );
        $generated1 = $query->getQueryString();

        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE
        );
        $query->addGroupBy('person');
        $generated2 = $query->getQueryString();
        $this->assertEquals($generated1, $generated2, 'AggregateQuery getQueryString() depending on how group by is applied');
    }

    /**
     * Test the addWhereJoin() method.
     */

    public function testAddWhereJoin()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'person'
        );
        $query->addWhereAndJoin('person', '>', 'constraint');
        $this->runQueryStringTest(
            $query->getQueryString(),
            [
                'group_bys' => ['person'],
                'additional_wheres' => [
                    "person__person.id > ('constraint')"
                ]
            ]
        );
    }

    /**
     * Test the Statistic::getFormula() method where macros in the formula are replaced with values from
     * the Query class.
     */

    public function testGetFormula()
    {
        $query = new AggregateQuery(
            'Cloud',
            'day',
            '2017-12-01',
            '2019-01-31',
            'configuration'
        );

        $realm = \Realm\Realm::factory('Cloud', self::$logger);
        $statistic = $realm->getStatisticObject('cloud_num_sessions_running');
        $generated = $statistic->getFormula($query);
        $expected = 'COALESCE(SUM(CASE duration.id WHEN 201800108 THEN agg.num_sessions_running ELSE agg.num_sessions_started END), 0) AS cloud_num_sessions_running';
        $this->assertEquals($expected, $generated, 'getFormula()');
    }

    /**
     * Test the count query.
     */

    public function testAggregateQueryCount()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'person'
        );
        $query->addStat('job_count');

        $generated = $query->getCountQueryString();
        $expected =<<<SQL
SELECT
  COUNT(*) AS row_count
FROM (
  SELECT
  SUM(1) AS total
  FROM
    %s
  WHERE
    %s
  GROUP BY
    %s
) AS a WHERE a.total IS NOT NULL
SQL;
        $froms = implode(
            ",\n    ",
            array_merge(
                self::EXPECTED_SQL['from']['jobs_by_day'],
                self::EXPECTED_SQL['from']['person'],
            )
        );
        $wheres = implode(
            "\n    AND ",
            array_merge(
                self::EXPECTED_SQL['where']['jobs_by_day'],
                self::EXPECTED_SQL['where']['person'],
            )
        );
        $groupBys = implode(
            ",\n    ",
            self::EXPECTED_SQL['group_by']['person']
        );
        $this->assertEquals(
            sprintf($expected, $froms, $wheres, $groupBys),
            $generated
        );
    }

    /**
     * Test the dimension values query. Note that the dimension values query does not prefix id,
     * name, or short_name with the group by id.
     */

    public function testAggregateQueryDimensionValues()
    {
        // Note that calling getDimensionValuesQuery() on a query class that specifies start and end
        // dates will fail due to a hard-coded array index reference.

        $query = new AggregateQuery(
            'Jobs',
            'day',
            null,
            null,
            'person'
        );
        $query->addStat('job_count');
        $this->runQueryStringTest(
            $query->getDimensionValuesQuery(),
            [
                'group_bys' => ['person'],
                'additional_wheres' => [
                    "person__person.id > ('constraint')"
                ]
            ]
        );
        // TODO: Make the code for building the query string its own function
        // and call it here with a search+replace. Can also use that function
        // above and do search+replace to indent.
        $expected =<<<SQL
SELECT
  person__person.id AS id,
  person__person.long_name AS name,
  person__person.short_name AS short_name,
  person__person.order_id AS _dimensionOrderValue
FROM
  modw.person person__person
WHERE
  person__person.id IN ( SELECT modw_filters.Jobs_person.person FROM modw_filters.Jobs_person )
GROUP BY
  person__person.id,
  person__person.short_name,
  person__person.long_name,
  person__person.order_id
ORDER BY
  person__person.order_id ASC
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query dimension values');
    }

    /**
     * This tests the following properties of a query:
     * - Two group bys with the same attribute table but different aggregate keys.
     *   -> Should assign distinct aliases for the two instances of the attribute table.
     * - Two group bys with the same primary attribute table and aggregate key
     *   but with different secondary attribute tables.
     *   -> Should join the primary attribute table to the aggregate table only once
     *      and each of the two secondary attribute tables should join that one instance
     *      of the primary attribute table.
     * - Two group bys with the same primary attribute table, different aggregate keys,
     *      and the same
     * Here "primary" means the attribute table that is being joined to the aggregate table.
     * ++===================================++=============================++
     * ||               Given               ||           Expect            ||
     * ++-----------+-----------+-----------++--------------+--------------++
     * ||  Primary  | Aggregate | Secondary ||    Primary   |  Secondary   ||
     * || attribute |   keys    | attribute ||   attribute  |  attribute   ||
     * ||  tables   |           |  tables   ||    tables    |    tables    ||
     * ++===========+===========+===========++==============+==============++
     * ||   Same    | Different |   None    || Two distinct |    None      ||
     * ||           |           |           ||   aliases    |              ||
     * ++-----------+-----------+-----------||--------------|--------------||
     * ||   Same    | Different |   Same    || Two distinct | Two distinct ||
     * ||           |           |           ||   aliases    |   aliases    ||
     * ++-----------+-----------+-----------||--------------|--------------||
     * ||   Same    |   Same    |   None    ||    Single    |    None      ||
     * ||           |           |           ||   instance   |              ||
     * ++-----------+-----------+-----------||--------------|--------------||
     * ||   Same    |   Same    | Different ||    Single    | Two distinct ||
     * ||           |           |           ||   instance   |   aliases    ||
     * ++-----------+-----------+-----------||--------------|--------------||
     * ||   Same    |   Same    |   Same    ||    Single    |    Single    ||
     * ||           |           |           ||   instance   |   instance   ||
     *
     * TODO: additional_join_constraints...
     */

    public function testMultiGroupBySameAttributeTableDifferentGroupByColumn()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            self::START_DATE,
            self::END_DATE,
            'fieldofscience'
        );
        $query->addGroupBy('parentscience');

        $actual = $query->getQueryString();
        $expected =<<<SQL
SELECT
  blah
FROM
  blah
WHERE
  blah
GROUP BY
  blah
ORDER BY
  blah
SQL;
        $this->assertEquals($expected, $actual);
    }
}
