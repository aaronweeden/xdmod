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
            'config_file_name' => 'datawarehouse.json',
            'config_base_dir'  => realpath('../artifacts/xdmod/realm')
        );

        \Realm\Realm::initialize(self::$logger, $options);
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
            '2016-12-01',
            '2017-01-31'
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
            '2016-12-01',
            '2017-01-31',
            'person'
        );

        $generated = $query->getQueryString();
        $expected  =<<<SQL
SELECT
  person__person.id as 'person_id',
  person__person.short_name as 'person_short_name',
  person__person.long_name as 'person_name',
  person__person.order_id as 'person_order_id'
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.person person__person
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND person__person.id = agg.person_id
GROUP BY person__person.id,
  person__person.short_name,
  person__person.long_name,
  person__person.order_id
ORDER BY person__person.order_id ASC
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query no statistic');
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
            '2016-12-01',
            '2017-01-31',
            'username'
        );

        $generated = $query->getQueryString();
        $expected  =<<<SQL
SELECT
  username__systemaccount.username as 'username_id',
  username__systemaccount.username as 'username_short_name',
  username__systemaccount.username as 'username_name',
  username__systemaccount.username as 'username_order_id'
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.systemaccount username__systemaccount
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND username__systemaccount.id = agg.systemaccount_id
GROUP BY username__systemaccount.username
ORDER BY username__systemaccount.username ASC
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query with alternate group by column');
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
            '2016-12-01',
            '2017-01-31',
            'person',
            'job_count'
        );

        $generated = $query->getQueryString();
        $expected  =<<<SQL
SELECT
  person__person.id as 'person_id',
  person__person.short_name as 'person_short_name',
  person__person.long_name as 'person_name',
  person__person.order_id as 'person_order_id',
  COALESCE(SUM(CASE duration.id WHEN 201600357 THEN agg.running_job_count ELSE agg.started_job_count END), 0) AS running_job_count,
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.person person__person
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND person__person.id = agg.person_id
GROUP BY person__person.id,
  person__person.short_name,
  person__person.long_name,
  person__person.order_id
ORDER BY person__person.order_id ASC
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query group by and main statistic');
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
            '2016-12-01',
            '2017-01-31',
            null,
            'job_count'
        );

        $generated = $query->getQueryString();

        // The query have have optional group by, order by, and limit fields. Due to the way it is
        // constructed, the blank line at the end of the query is required if none of these are
        // present.

        $expected  =<<<SQL
SELECT
  COALESCE(SUM(CASE duration.id WHEN 201600357 THEN agg.running_job_count ELSE agg.started_job_count END), 0) AS running_job_count,
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001

SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query main statistic no group by');
    }

    /**
     * Create an aggregate query and then add a group by and a statistic.
     */

    public function testAggregateQueryAddGroupByAndStatistic()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31'
        );
        $query->addGroupBy('person');
        $query->addStat('job_count');

        $generated = $query->getQueryString();
        $expected  =<<<SQL
SELECT
  person__person.id as 'person_id',
  person__person.short_name as 'person_short_name',
  person__person.long_name as 'person_name',
  person__person.order_id as 'person_order_id',
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.person person__person
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND person__person.id = agg.person_id
GROUP BY person__person.id,
  person__person.short_name,
  person__person.long_name,
  person__person.order_id
ORDER BY person__person.order_id ASC
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query add group by and statistic');
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
  person__person.id as 'person_id',
  person__person.short_name as 'person_short_name',
  person__person.long_name as 'person_name',
  person__person.order_id as 'person_order_id',
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.person person__person
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND person__person.id = agg.person_id
GROUP BY person__person.id,
  person__person.short_name,
  person__person.long_name,
  person__person.order_id
ORDER BY job_count desc,
  person__person.order_id ASC
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
    modw.person person__person
  WHERE
    duration.id = agg.day_id
    AND agg.day_id between 201600357 and 201700001
    AND person__person.id = agg.person_id
  GROUP BY
    person__person.id,
    person__person.short_name,
    person__person.long_name,
    person__person.order_id
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
  queue__queue.id as 'queue_id',
  queue__queue.id as 'queue_short_name',
  queue__queue.id as 'queue_name',
  queue__queue.id as 'queue_order_id',
  COALESCE(SUM(agg.ended_job_count), 0) AS job_count
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.queue queue__queue
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND queue__queue.id = agg.queue
  AND queue__queue.resource_id = agg.task_resource_id
GROUP BY queue__queue.id
ORDER BY job_count desc,
  queue__queue.id ASC
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
  person__person.id as 'person_id',
  person__person.short_name as 'person_short_name',
  person__person.long_name as 'person_name',
  person__person.order_id as 'person_order_id'
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.person person__person
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND person__person.id = agg.person_id
  AND person__person.id > ('constraint')
GROUP BY person__person.id,
  person__person.short_name,
  person__person.long_name,
  person__person.order_id
ORDER BY person__person.order_id ASC
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
    modw.person person__person
  WHERE
    duration.id = agg.day_id
    AND agg.day_id between 201600357 and 201700001
    AND person__person.id = agg.person_id
  GROUP BY
    person__person.id,
    person__person.short_name,
    person__person.long_name,
    person__person.order_id
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
  person__person.id AS id,
  person__person.long_name AS name,
  person__person.short_name AS short_name,
  person__person.order_id AS _dimensionOrderValue
FROM modw.person person__person
WHERE person__person.id IN ( SELECT modw_filters.Jobs_person.person FROM modw_filters.Jobs_person )
GROUP BY person__person.id, person__person.short_name, person__person.long_name, person__person.order_id
ORDER BY person__person.order_id ASC
SQL;
        $this->assertEquals($expected, $generated, 'Aggregate query dimension values');
    }

    public function testTwoGroupBysSameTableDifferentColumn()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31',
            'fieldofscience'
        );
        $query->addGroupBy('parentscience');

        $generated = $query->getQueryString();
        $expected =<<<SQL
SELECT
  fieldofscience__fieldofscience_hierarchy.id as 'fieldofscience_id',
  fieldofscience__fieldofscience_hierarchy.description as 'fieldofscience_short_name',
  fieldofscience__fieldofscience_hierarchy.description as 'fieldofscience_name',
  fieldofscience__fieldofscience_hierarchy.description as 'fieldofscience_order_id',
  fieldofscience__fieldofscience_hierarchy.parent_id as 'parentscience_id',
  fieldofscience__fieldofscience_hierarchy.parent_description as 'parentscience_short_name',
  fieldofscience__fieldofscience_hierarchy.parent_description as 'parentscience_name',
  fieldofscience__fieldofscience_hierarchy.parent_description as 'parentscience_order_id'
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.fieldofscience_hierarchy fieldofscience__fieldofscience_hierarchy
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND fieldofscience__fieldofscience_hierarchy.id = agg.fos_id
GROUP BY fieldofscience__fieldofscience_hierarchy.id,
  fieldofscience__fieldofscience_hierarchy.description,
  fieldofscience__fieldofscience_hierarchy.parent_id,
  fieldofscience__fieldofscience_hierarchy.parent_description
ORDER BY fieldofscience__fieldofscience_hierarchy.description.code ASC,
  fieldofscience__fieldofscience_hierarchy.parent_description
SQL;
        $this->assertEquals($expected, $generated);
    }

    public function testMultiGroupBysSameTableDifferentAggregateKeysAndDoubleHops()
    {
        $query = new AggregateQuery(
            'Jobs',
            'day',
            '2016-12-01',
            '2017-01-31',
            'institution'
        );
        $query->addGroupBy('institution_state');
        $query->addGroupBy('institution_country');
        $query->addGroupBy('pi_institution');
        $query->addGroupBy('pi_institution_state');
        $query->addGroupBy('pi_institution_country');

        $generated = $query->getQueryString();
        $expected =<<<SQL
SELECT
  institution__organization.id as 'institution_id',
  institution__organization.short_name as 'institution_short_name',
  institution__organization.long_name as 'institution_name',
  institution__organization.id as 'institution_order_id',
  institution_state__state.id as 'institution_state_id',
  institution_state__state.name as 'institution_state_short_name',
  institution_state__state.name as 'institution_state_name',
  institution_state__state.name as 'institution_state_order_id',
  institution_country__country.id as 'institution_country_id',
  institution_country__country.name as 'institution_country_short_name',
  institution_country__country.name as 'institution_country_name',
  institution_country__country.name as 'institution_country_order_id',
  pi_institution__organization.id as 'pi_institution_id',
  pi_institution__organization.short_name as 'pi_institution_short_name',
  pi_institution__organization.long_name as 'pi_institution_name',
  pi_institution__organization.id as 'pi_institution_order_id',
  pi_institution_state__state.id as 'pi_institution_state_id',
  pi_institution_state__state.name as 'pi_institution_state_short_name',
  pi_institution_state__state.name as 'pi_institution_state_name',
  pi_institution_state__state.name as 'pi_institution_state_order_id',
  pi_institution_country__country.id as 'pi_institution_country_id',
  pi_institution_country__country.name as 'pi_institution_country_short_name',
  pi_institution_country__country.name as 'pi_institution_country_name',
  pi_institution_country__country.name as 'pi_institution_country_order_id'
FROM
  modw_aggregates.jobfact_by_day agg,
  modw.days duration,
  modw.organization institution__organization,
  modw.state institution_state__state,
  modw.country institution_country__country,
  modw.organization pi_institution__organization,
  modw.state pi_institution_state__state,
  modw.country pi_institution_country__country
WHERE
  duration.id = agg.day_id
  AND agg.day_id between 201600357 and 201700001
  AND institution__organization.id = agg.person_organization_id
  AND institution_state__state.id = institution__organization.state_id
  AND institution_country__country.id = institution__organization.country_id
  AND pi_institution__organization.id = agg.piperson_organization_id
  AND pi_institution_state__state.id = pi_institution__organization.state_id
  AND pi_institution_country__country.id = pi_institution__organization.country_id
GROUP BY institution__organization.id,
  institution__organization.short_name,
  institution__organization.long_name,
  institution_state__state.id,
  institution_state__state.name,
  institution_country__country.id,
  institution_country__country.name,
  pi_institution__organization.id,
  pi_institution__organization.short_name,
  pi_institution__organization.long_name,
  pi_institution_state__state.id,
  pi_institution_state__state.name,
  pi_institution_country__country.id,
  pi_institution_country__country.name
ORDER BY institution__organization.id ASC,
  institution_state__state.name ASC,
  institution_country__country.name ASC,
  pi_institution__organization.id ASC,
  pi_institution_state__state.name ASC,
  pi_institution_country__country.name ASC'
SQL;
        $this->assertEquals($expected, $generated);
    }

    // TODO: Test order of adding group bys
    // TODO: Add tests of other output besides getQueryString, e.g., getAttributeValues
}
