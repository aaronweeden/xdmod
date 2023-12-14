<?php

 namespace RegressionTests\Controllers;

 use RegressionTests\TestHarness\RegressionTestHelper;

/**
 * Test the usage explorer for jobs realm regressions.
 */
class UsageExplorerResourcespecificationsTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \RegressionTestHelper
     */
    private static $helper;

    /**
     * Create the helper and authenticate.
     */
    public static function setUpBeforeClass()
    {
        self::$helper = new RegressionTestHelper();
        self::$helper->authenticate();
    }

    /**
     * Log out and output any messages generated by tests.
     */
    public static function tearDownAfterClass()
    {
        self::$helper->logout();
        self::$helper->outputMessages();
    }

    /**
     * Test usage explorer CSV export.
     *
     * @group regression
     * @group UsageExplorer
     * @dataProvider csvExportProvider
     */
    public function testCsvExport($testName, $input, $expectedFile, $userRole)
    {
        $this->assertTrue(self::$helper->checkCsvExport($testName, $input, $expectedFile, $userRole));
    }

    public function csvExportProvider()
    {
        $statistics = [
            'cpu_hours_available',
            'gpu_hours_available',
            'cpu_node_hours_available',
            'gpu_node_hours_available',
            'avg_number_of_cpu_nodes',
            'avg_number_of_gpu_nodes',
            'avg_number_of_cpu_cores',
            'avg_number_of_gpu_cores'
        ];

        $groupBys = [
            'resource',
            'resource_type',
            'provider',
            'resource_allocation_type'
        ];

        $settings = [
            'realm' => ['ResourceSpecifications'],
            'dataset_type' => ['aggregate', 'timeseries'],
            'statistic' => $statistics,
            'group_by' => $groupBys,
            'aggregation_unit' => ['Day', 'Month', 'Quarter', 'Year']
        ];

        return RegressionTestHelper::generateTests($settings);
    }
}
