<?php
/**
 * This various aspects of the Query class as well as parts of the Realm, GroupBy, and Statistics
 * classes that require database access.
 */

namespace ComponentTests\Query;

use CCR\Log as Logger;
use DataWarehouse\Query\AggregateQuery;
use Psr\Log\LoggerInterface;

class AggregateQueryTest extends QueryTestCase
{
    protected static $QUERY_CLASS = '\DataWarehouse\Query\AggregateQuery';

    /**
     * Create an aggregate query with no group by or statistic. We will not be able to generate the
     * query string with no fields for the SELECT clause.
     *
     *
     */

    public function testAggregateQueryNoStatisticNoGroupBy()
    {
        $this->expectException(\Exception::class);
        $this->runQueryTest();
    }

    /**
     * Create an aggregate query with a group by but no statistic. The select field will contain the
     * group by id, short name, and name.
     */

    public function testAggregateQueryNoStatistic()
    {
        $this->runQueryTest(['group_by' => 'person']);
    }

    /**
     * Create an aggregate query with a group by but no statistic. The select field will contain the
     * group by id, short name, and name.
     */

    public function testAggregateQueryWithAlternateGroupByColumn()
    {
        $this->runQueryTest(['group_by' => 'username']);
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

        $this->runQueryTest([
            'group_by' => 'person',
            'statistic' => 'job_count'
        ]);
    }

    /**
     * Create an aggregate query with a statistic but no group by. This is essentially a group by None.
     */

    public function testAggregateQueryStatisticNoGroupBy()
    {
        // Specifying the statistic in the constructor calls Query::setStat() which adds all
        // available statistics. Note that the order of the statistics in the query are dependent on
        // the oder specified in the config file.

        $this->runQueryTest(['statistic' => 'job_count']);
    }

    /**
     * Create an aggregate query and then add a group by and a statistic.
     */

    public function testAggregateQueryAddGroupByAndStatistic()
    {
        $this->runQueryTest([
            'add_group_by' => 'person',
            'add_stat' => 'job_count'
        ]);
    }

    /**
     * Create an aggregate query with a group by and then add a statistic.
     */

    public function testAggregateQueryWithGroupByAddStatistic()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31',
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

        $generated = $query->getQueryString(10, 0); // Also test limit=10 and offset=0
        $expected  =<<<SQL
SELECT
  person.id as 'person_id',
  person.short_name as 'person_short_name',
  person.long_name as 'person_name',
  person.order_id as 'person_order_id',
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.person person
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND person.id = agg.person_id
GROUP BY person.id,
  person.short_name,
  person.long_name,
  person.order_id
ORDER BY job_count desc,
  person.order_id ASC
LIMIT 10 OFFSET 0
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query with group by add statistic');

        $generated = $query->getCountQueryString();
        $expected =<<<SQL
SELECT
  COUNT(*) AS row_count
FROM (
  SELECT
  SUM(1) AS total
  FROM
    modw_aggregates.jobfact_by_day agg,
    modw.days duration,
    modw.person person
  WHERE
    duration.id = agg.day_id
    AND agg.day_id between 201600357 and 201700001
    AND person.id = agg.person_id
  GROUP BY
    person.id,
    person.short_name,
    person.long_name,
    person.order_id
) AS a WHERE a.total IS NOT NULL
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query count with group by add statistic ');
    }

    /**
     * Test adding an additional JOIN constraint to the query, as is used in GroupByQueue.
     */

    public function testAggregateQueryAdditionalJoinConstraint()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31'
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


        $generated = $query->getQueryString();
        $expected  =<<<SQL
SELECT
  queue.id as 'queue_id',
  queue.id as 'queue_short_name',
  queue.id as 'queue_name',
  queue.id as 'queue_order_id',
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.queue queue
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND queue.id = agg.queue
  AND queue.resource_id = agg.task_resource_id
GROUP BY queue.id
ORDER BY job_count desc,
  queue.id ASC
SQL;
        $this->assertEquals($expected, $generated, 'Additional join constraint');
    }

    /**
     * Test using group by none, which is a special case of aggregation unit group-by.
     */

    public function testAggregateQueryGroupByNone()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31'
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
        $generated = $query->getQueryString();
        $expected =<<<SQL
SELECT
  -9999 as 'none_id',
  'Screwdriver' as 'none_short_name',
  'Screwdriver' as 'none_name',
  'Screwdriver' as 'none_order_id',
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001

ORDER BY job_count desc
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query group by none');
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
            '2016-12-01',
            '2017-01-31',
            'person'
        );
        $generated1 = $query->getQueryString();

        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31'
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
        $expected =<<<SQL
SELECT
  person.id as 'person_id',
  person.short_name as 'person_short_name',
  person.long_name as 'person_name',
  person.order_id as 'person_order_id'
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.person person
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND person.id = agg.person_id
  AND person.id > ('constraint')
GROUP BY person.id,
  person.short_name,
  person.long_name,
  person.order_id
ORDER BY person.order_id ASC
SQL;

        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31',
            'person'
        );
        $query->addWhereAndJoin('person', '>', 'constraint');

        $generated = $query->getQueryString();
        $this->assertEquals($expected, $generated, 'AggregateQuery::addWhereJoin()');
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
            '2016-12-01',
            '2017-01-31',
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
    modw_aggregates.jobfact_by_day agg,
    modw.days duration,
    modw.person person
  WHERE
    duration.id = agg.day_id
    AND agg.day_id between 201600357 and 201700001
    AND person.id = agg.person_id
  GROUP BY
    person.id,
    person.short_name,
    person.long_name,
    person.order_id
) AS a WHERE a.total IS NOT NULL
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query count');
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

        $generated = $query->getDimensionValuesQuery();
        $expected =<<<SQL
SELECT
  person.id AS id,
  person.long_name AS name,
  person.short_name AS short_name,
  person.order_id AS _dimensionOrderValue
FROM modw.person person
WHERE person.id IN ( SELECT modw_filters.Jobs_person.person FROM modw_filters.Jobs_person )
GROUP BY person.id, person.short_name, person.long_name, person.order_id
ORDER BY person.order_id ASC
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query dimension values');
    }
}
