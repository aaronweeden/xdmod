<?php
/**
 * XDMoD implements descriptive attributes using GroupBy classes.
 * @see iGroupBy
 */

namespace Realm;

use CCR\DB;
use ETL\DbModel\Query as DbQuery;
use \DataWarehouse\Query\iQuery;
use \DataWarehouse\Query\Model\OrderBy;
use \DataWarehouse\Query\Model\Parameter;
use \DataWarehouse\Query\Model\Schema;
use \DataWarehouse\Query\Model\Field;
use \DataWarehouse\Query\Model\Table;
use \DataWarehouse\Query\Model\TableField;
use \DataWarehouse\Query\Model\WhereCondition;
use \DataWarehouse\Query\TimeAggregationUnit;
use Psr\Log\LoggerInterface;

class GroupBy extends \CCR\Loggable implements iGroupBy
{
    /**
     * @var Realm The realm that this GroupBy belongs to.
     */

    protected $realm = null;

    /**
     * @var string The name of the module that defined this group by
     */

    protected $moduleName = null;

    /**
     * @var string The short identifier.
     */

    protected $id = null;

    /**
     * @var string The display name.
     */

    protected $name = null;

    /**
     * @var string Human-readable description supporting basic HTML formatting.
     */

    protected $description = null;

    /**
     * @var \DataWarehouse\Query\Model\Table Attribute table object.
     */

    protected $attributeTableObj = null;

    /**
     * @var DbQuery The query provides a mechanism for the GroupBy class to
     *   enumerate a list of the current values for the dimensional attribute that it describes. This
     *   is constructed from the same JSON configuration object used by the ETL.
     */

    protected $query = null;

    /**
     * @var stdClass The query represented as a stdClass.
     */

    protected $queryAsStdClass = null;

    /**
     * @var int A numerical ordering hint as to how this realm should be displayed visually relative
     *   to other realms. Lower numbers are displayed first. If no order is specified, 0 is assumed.
     */

    protected $order = 0;

    /**
     * @var string Category used to group attributes, used in the Job Viewer filters.
     */

    protected $category = 'uncategorized';

    /**
     * @var boolean Indicates that this dimension should be shown in the metric catalog and is
     *   available for drill-down in the user interface.
     */

    protected $showInCatalog = true;

    /**
     * @var boolean Indicates that this dimension is an aggregation unit such as day, month,
     * quarter, year. This may trigger special handling when constructing queries.
     */

    protected $isAggregationUnit = false;

    /**
     * @var array An associative array specifying chart defaults. Note that "y" and
     *   "n" are used rather than true and false to ensure that existing code does not break.
     */

    protected $chartOptions = array(
        'combine_method' => 'stack',
        'dataset_display_type' => array(
            'aggregate' => 'h_bar',
            'timeseries' => 'line'
        ),
        'dataset_type' => 'aggregate',
        'enable_errors' => 'y',
        'enable_trend_line' => 'y',
        'limit' => array(
            'multichart_page' => 3,
            'default' => 10
        ),
        'log_scale' => 'n',
        'offset' => 0,
        'show_aggregate_labels' => 'n',
        'show_error_bars' => 'n',
        'show_error_labels' => 'n',
        'show_guide_lines' => 'y',
        'show_legend' => 'y',
        'show_trend_line' => 'n'
    );

    /**
     * @var array An associative array specifying the chart type to use for a given dataset type
     *   when using this group by. The keys are the dataset type (e.g., aggregate or timeseries) and
     *   the values are chart types.
     */

    protected $datasetTypeToChartDisplayTypeMap = array(
        'aggregate' => 'h_bar',
        'timeseries' => 'line'
    );

    /**
     * @var int PHP order specificaiton to determine how the query should sort results containing
     *   this GroupBy.
     * @see http://php.net/manual/en/function.array-multisort.php
     */

    protected $sortOrder = SORT_DESC;

    /**
     * @var boolean Set to true if this group by should not be utilized.
     */

    protected $disabled = false;

    /**
     * @var boolean Set to true if this group by should allow all values to be shown for all users.
     */

    protected $showAllDimensionValues = false;

    /**
     * @see iGroupBy::factory()
     */

    public static function factory($shortName, \stdClass $config, Realm $realm, LoggerInterface $logger = null)
    {
        return new static($shortName, $config, $realm, $logger);
    }

    /**
     * This constructor is meant to be called by factory() but it cannot be made private because we
     * extend Loggable, which has a public constructor.
     *
     * @param string $shortName The short name for this statistic
     * @param stdClass $config An object contaning the configuration specificaiton.
     * @param Realm $realm Realm object that this GroupBy will belong to.
     * @param LoggerInterface|null $logger A Monolog Logger instance that will be utilized during processing.
     */

    public function __construct($shortName, \stdClass $config, Realm $realm, LoggerInterface $logger = null)
    {
        parent::__construct($logger);

        // The __toString() method needs these to be set and logAndThrowException() calls
        // __toString() so assign these at the top.

        $this->id = $shortName;
        $this->moduleName = $realm->getModuleName();
        $this->realm = $realm;

        if ( empty($shortName) ) {
            $this->logger->logAndThrowException('GroupBy short name not provided');
        } elseif ( ! is_string($shortName) ) {
            $this->logger->logAndThrowException(
                sprintf('GroupBy short name must be a string, %s provided: %s', $shortName, gettype($shortName))
            );
        } elseif ( null === $config ) {
            $this->logger->logAndThrowException('No GroupBy configuration provided');
        }

        // Verify the types of the various configuration options

        $messages = array();
        $configTypes = array(
            'query' => 'object',
            'description_html' => 'string',
            'name' => 'string'
        );

        if ( ! \xd_utilities\verify_object_property_types($config, $configTypes, $messages) ) {
            $this->logAndThrowException(
                sprintf('Error verifying GroupBy configuration: %s', implode(', ', $messages))
            );
        }

        $optionalConfigTypes = array(
            'category' => 'string',
            'chart_options' => 'object',
            'data_sort_order' => 'string',
            'disabled' => 'bool',
            'module' => 'string',
            'order' => 'int',
            'show_in_catalog' => 'bool',
            'show_all_dimension_values' => 'bool'
        );

        if ( ! \xd_utilities\verify_object_property_types($config, $optionalConfigTypes, $messages, true) ) {
            $this->logAndThrowException(
                sprintf('Error verifying GroupBy configuration: %s', implode(', ', $messages))
            );
        }

        foreach ( $config as $key => $value ) {
            switch ($key) {
                case 'query':
                    $this->query = new DbQuery($value, '`', $logger);
                    break;
                case 'show_in_catalog':
                    $this->showInCatalog = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'category':
                    $this->category = trim($value);
                    break;
                case 'chart_options':
                    foreach ( $value as $optionKey => $optionValue ) {
                        if ( ! array_key_exists($optionKey, $this->chartOptions) ) {
                            $this->logger->warning(
                                sprintf("Unsupported option in '%s' for group by '%s': '%s'", $key, $this->id, $optionKey)
                            );
                            continue;
                        }
                        // Enable special handling of various options
                        switch ($optionKey) {
                            case 'enable_errors':
                            case 'enable_trend_line':
                            case 'log_scale':
                            case 'show_aggregate_labels':
                            case 'show_error_bars':
                            case 'show_error_labels':
                            case 'show_guide_lines':
                            case 'show_legend':
                            case 'show_trend_line':
                                // For historical reasons, use 'y' and 'n' as boolean values. This
                                // may be able to be changed to true/false in the future.
                                $this->chartOptions[$optionKey] = (
                                    filter_var($optionValue, FILTER_VALIDATE_BOOLEAN)
                                    ? 'y'
                                    : 'n'
                                );
                                break;
                            case 'offset':
                                $this->chartOptions[$optionKey] = filter_var($optionValue, FILTER_VALIDATE_INT);
                                break;
                            case 'dataset_display_type':
                                if ( ! is_object($optionValue) ) {
                                    continue 2;
                                }
                                foreach ($optionValue as $datasetType => $chartDisplayValue ) {
                                    if ( ! array_key_exists($datasetType, $this->chartOptions[$optionKey]) ) {
                                        $this->logger->warning(
                                            sprintf("Unsupported dataset type in '%s' for group by '%s': '%s'", $optionKey, $this->id, $datasetType)
                                        );
                                        continue;
                                    }
                                    $this->chartOptions[$optionKey][$datasetType] = $chartDisplayValue;
                                }
                                break;
                            case 'limit':
                                if ( ! is_object($optionValue) ) {
                                    continue 2;
                                }
                                foreach ($optionValue as $pageType => $limitValue ) {
                                    if ( ! array_key_exists($pageType, $this->chartOptions[$optionKey]) ) {
                                        $this->logger->warning(
                                            sprintf("Unsupported page type in '%s' for group by '%s': '%s'", $optionKey, $this->id, $pageType)
                                        );
                                        continue;
                                    }
                                    $this->chartOptions[$optionKey][$pageType] = $limitValue;
                                }
                                break;
                            default:
                                if ( ! array_key_exists($optionKey, $this->chartOptions) ) {
                                    $this->logger->notice(
                                        sprintf(
                                            "Unknown key in chart_defaults for realm '%s' group by '%s': '%s'",
                                            $this->realm->getName(),
                                            $this->id,
                                            $optionKey
                                        )
                                    );
                                } else {
                                    $this->chartOptions[$optionKey] = $optionValue;
                                }
                                break;
                        }
                    }
                    break;
                case 'chart_display_type_map':
                    break;
                case 'data_sort_order':
                    // The sort order is specified in the JSON config file as the string
                    // representation of a PHP constant so convert it to an integer in order to
                    // properly use it. See https://php.net/manual/en/function.array-multisort.php
                    if (!empty($value)){
                        $this->setSortOrder(constant($value));
                    } else {
                        $this->setSortOrder(null);
                    }
                    break;
                case 'description_html':
                    $this->description = trim($value);
                    break;
                case 'disabled':
                    $this->disabled = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'module':
                    $this->moduleName = trim($value);
                    break;
                case 'name':
                    $this->name = trim($value);
                    break;
                case 'order':
                    $this->order = filter_var($value, FILTER_VALIDATE_INT);
                    break;
                case 'show_all_dimension_values':
                    $this->showAllDimensionValues = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                default:
                    $this->logger->notice(
                        sprintf("Unknown key in definition for realm '%s' group by '%s': '%s'", $this->realm->getName(), $this->id, $key)
                    );
                    break;
            }
        }

        // Ensure the attribute values query has the required columns as we will rely on these later
        // on.

        if (
            false === $this->query->getRecord('id') ||
            false === $this->query->getRecord('short_name') ||
            false === $this->query->getRecord('name') // For historical reasons, the long name is simply "name"
        ) {
            $this->logAndThrowException('The query must specify id, short_name, and name records');
        }

        // Determine if this is an aggregation unit
        $this->isAggregationUnit = TimeAggregationUnit::isTimeAggregationUnitName($this->id);

        // Transform the joins into the format expected by ETL\DbModel\Query
        $joins = $this->query->joins;
        foreach ($joins as $join) {
            // Set schema
            if (isset($join->right_schema)) {
                $join->schema = $join->right_schema;
                unset($join->right_schema);
            } else {
                $join->schema = 'modw';
            }
            // Set name and alias
            $join->name = $join->right_table;
            if ($this->isAggregationUnit()) {
                $join->alias = iQuery::DATE_TABLE_ALIAS;
            } else {
                $join->alias = $this->getTableAlias($join->name);
            }
            // Set on
            $additionalJoinConditions = $join->and ?? [];
            $joinConditions = [$join, ...$additionalJoinConditions];
            $joinConditionParts = [];
            foreach ($joinConditions as $joinCondition) {
                $leftTable = $this->getTableAlias($joinCondition->left_table) ?? 'agg';
                $leftColumn = $joinCondition->left_column ?? $joinCondition->agg_column;
                if (!isset($leftColumn) || !isset($joinCondition->right_table) || !isset($joinCondition->right_column)) {
                    // Throw an error
                }
                $joinConditionParts[] = sprintf(
                    "%s.%s = %s.%s",
                    $leftTable,
                    $leftColumn,
                    $joinCondition->right_table,
                    $joinCondition->right_column
                );
            }
            $join->on = implode(' AND ', $joinConditionParts);
            unset($join->and);
            unset($join->right_table);
            unset($join->right_column);
        }
        $this->query->joins = $joins;
        $this->queryAsStdClass = $this->query->toStdClass();
        $this->attributeTableObj = new Table(
            new Schema($joins[0]->right_schema ?? 'modw'),
            trim($joins[0]->name),
            trim($joins[0]->alias)
        );
        foreach ($joins as $join) {
            if (isset($join->on)) {
                $join->on = $this->verifyAndReplaceTableAlias($join->on);
            }
        }
        $this->query->joins = $joins;
        $records = $this->query->records;
        foreach ($records as $key => $value) {
            $records[$key] = $this->verifyAndReplaceTableAlias($value);
        }
        $this->query->records = (object)$records;
        foreach (['orderby', 'where'] as $prop) {
            $propDefs = $this->query->$prop ?? null;
            if (null !== ($propDefs)) {
                $newPropDefs = [];
                foreach ($propDefs as $propDef) {
                    $newPropDefs[] = $this->verifyAndReplaceTableAlias($propDef);
                }
                $this->query->$prop = $newPropDefs;
            }
        }
        $this->queryAsStdClass = $this->query->toStdClass();
        $this->logger->debug(sprintf('Created %s', $this));
    }

    /**
     * @see iGroupBy::getRealm()
     */

    public function getRealm()
    {
        return $this->realm;
    }

    /**
     * Note: Was getName()
     *
     * @see iGroupBy::getId()
     */

    public function getId()
    {
        return $this->id;
    }

    /**
     * Note: Was getLabel()
     *
     * @see iGroupBy::getName()
     */

    public function getName()
    {
        return $this->realm->getVariableStore()->substitute($this->name);
    }

    /**
     * Note: Was getInfo()
     *
     * @see iGroupBy::getHtmlDescription()
     */

    public function getHtmlDescription()
    {
        return $this->realm->getVariableStore()->substitute($this->description);
    }

    /**
     * Note: Was getDescription()
     *
     * @see iGroupBy::getHtmlNameAndDescription()
     */

    public function getHtmlNameAndDescription()
    {
        return sprintf("<b>%s</b>: %s", $this->getName(), $this->getHtmlDescription());
    }

    /**
     * @see iGroupBy::getModuleName()
     */

    public function getModuleName()
    {
        return $this->moduleName;
    }

    /**
     * @see iGroupBy::getOrder()
     */

    public function getOrder()
    {
        return $this->order;
    }

    /**
     * @see iGroupBy::setSortOrder()
     */

    public function setSortOrder($sortOrder = SORT_DESC)
    {
        $validSortOrders = array(
            SORT_ASC,
            SORT_DESC,
            SORT_REGULAR,
            SORT_NUMERIC,
            SORT_STRING,
            SORT_LOCALE_STRING,
            SORT_NATURAL
        );

        if ( null !== $sortOrder && ! in_array($sortOrder, $validSortOrders) ) {
            $this->logAndThrowException(sprintf("Invalid sort option: %d", $sortOrder));
        }

        $this->sortOrder = $sortOrder;
    }

    /**
     * @see iGroupBy::getSortOrder()
     */

    public function getSortOrder()
    {
        return $this->sortOrder;
    }

    /**
     * @see iGroupBy::showInMetricCatalog()
     */

    public function showInMetricCatalog()
    {
        return $this->showInCatalog;
    }

    /**
     * @see iGroupBy::isAggregationUnit()
     */

    public function isAggregationUnit()
    {
        return $this->isAggregationUnit;
    }

    /**
     * Qualify the given column name by prefixing it with the id of this group by so it is unique
     * when used in a Query.
     *
     * @param string $name The name of the column to prefix
     *
     * @return string The qualified column name
     */

    protected function qualifyColumnName($name, $multi = false)
    {
        return ( true === $multi ? sprintf('%s_%s', $this->id, $name) : $name );
    }

    /**
     * Filters in a request are identified in 2 ways:
     * 1) By looking for a parameter with the short name of this group by and creating a filter with
     *    its (singular) value.
     * 2) By looking for a parameter with the short name appended with "_filter" and creating a
     *    filter with the (possibly multiple) values.
     *
     * @param array $request The HTTP request
     *
     * @return array A list of zero or more filters for this group by identified in the request.
     */

    protected function pullFilterValuesFromRequest(array $request)
    {
        $filterList = array();

        // Request parameters with "_filter" appended to the short name of this group by may include
        // one or more values to filter on.

        $filterKey = sprintf('%s_filter', $this->id);
        if ( isset($request[$filterKey]) && '' != $request[$filterKey] ) {
            $filterList = explode(',', $request[$filterKey]);
        }

        // Request parameters with the same short name as this group by may contain single values to
        // filter on.

        if ( isset($request[$this->id]) && '' != $request[$this->id] ) {
            $filterList[] = $request[$this->id];
        }

        return $filterList;
    }

    /**
     * @see iGroupBy::generateQueryFiltersFromRequest()
     * @see pullFilterValuesFromRequest()
     *
     * Was pullQueryParameters()
     */

    public function generateQueryFiltersFromRequest(array $request)
    {
        $filterList = array();
        $aggregateFilters = array();
        $requestFilters = $this->pullFilterValuesFromRequest($request);

        if ( ('none' == $this->id) || 0 == count($requestFilters) ) {
            return $filterList;
        }

        $db = DB::factory('datawarehouse');

        foreach ( $requestFilters as $filterValues ) {
            foreach ( $this->attributeToAggregateKeyMap as $aggregateKey ) {
                $aggregateFilters[$aggregateKey][] = $db->quote($filterValues);
            }
        }

        // For each aggregate column filter, check to see if there is a mapping query to translate
        // between the filter value and aggregate value. If so, replace the __filter_values__
        // placeholder in the attribute filter map sub-query with the list of filters from the
        // request. If no attribute filter query was specified simply use the list of values.

        foreach ( $aggregateFilters as $aggregateKeyColumn => $filterValues ) {
            $fieldIdQuery = implode(",", $filterValues);

            // Should the aggregate key column include the aggregate table name?
            $filterParameter = new Parameter($aggregateKeyColumn, 'IN', "($fieldIdQuery)");
            $this->logger->debug(
                sprintf('%s Generate parameter from request: %s', $this, $filterParameter)
            );
            $filterList[] = $filterParameter;
        }

        return $filterList;
    }

    /**
     * @see iGroupBy::generateQueryParameterLabelsFromRequest()
     * Was pullQueryParameterDescriptions()
     */

    public function generateQueryParameterLabelsFromRequest(array $request)
    {
        $labelList = array();
        $attributeKeyFilters = array();
        $query = null;

        $requestFilters = $this->pullFilterValuesFromRequest($request);
        $filterCount = count($requestFilters);

        if ( ('none' == $this->id) ||  0 == $filterCount ) {
            return $labelList;
        }

        $db = DB::factory('datawarehouse');

        foreach ( $requestFilters as $filterValues ) {
            foreach ( $this->attributeToAggregateKeyMap as $attributeKey => $aggregateKey ) {
                $attributeKeyFilters[$attributeKey][] = $db->quote($filterValues);
            }
        }

        // Construct the where conditions for each key column. We will add these to the query or
        // replace the placeholder in the attribute description query.

        $whereConditions = array();
        foreach ( $attributeKeyFilters as $attributeKey => $filterValues ) {
            $whereConditions[] = sprintf("%s IN (%s)", $attributeKey, implode(",", $filterValues));
        }

        // Clone the attribute values query so we don't modify the original object and remove
        // all columns except for the long name and add WHERE conditions from the filters.

        // Use the attribute values query as a template so we don't modify the original object
        $queryConfig = $this->query->toStdClass();

        foreach ( $queryConfig->records as $column => $formula ) {
            if ( 'name' != $column ) {
                unset($queryConfig->records->$column);
            }
        }

        if ( ! isset($queryConfig->where) || ! is_array($queryConfig->where) ) {
            $queryConfig->where = $whereConditions;
        } else {
            $queryConfig->where = array_merge($queryConfig->where, $whereConditions);
        }

        // Execute the attribute values query, selecting only the long_name column

        $query = new DbQuery($queryConfig, '`', $this->logger);
        $query = $query->getSql();

        // Return the results of the first column

        $this->logger->debug(
            sprintf("%s Fetch query parameter labels with query\n%s", $this, $query)
        );
        $db = DB::factory('datawarehouse');
        $stmt = $db->handle()->prepare($query);
        $stmt->execute();
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

        $labels = array();
        foreach ( $result as $fieldLabel ) {
            $labels[] = $fieldLabel;
        }

        // Generate the label string using a coma to separate labels. Would it be better to use a
        // semi-colon? Some labels that already contain comas such as "Last Name, First Name".

        $labelString = sprintf(
            ( $filterCount > 1 ? '( %s )' : ' %s' ),
            implode(',  ', $labels)
        );

        $labelList[] = sprintf('%s = %s', $this->getName(), $labelString);

        $this->logger->debug(
            sprintf('%s Generated query parameter labels %s', $this, implode('; ', $labelList))
        );

        return $labelList;
    }

    /**
     * @see iGroupBy::applyTo()
     */

    public function applyTo(iQuery $query)
    {
        $this->logger->debug(sprintf('Apply GroupBy %s to %s', $this, $query));

        // When applying an aggregation unit GroupBy the aggregation tables will already have been
        // added by Query::setDuration()

        if ( ! $this->isAggregationUnit && 'none' != $this->id ) {
            foreach ($this->query->joins as $join) {
                $query->addTable(new Table(
                    new Schema($join->schema),
                    $join->name,
                    $join->alias
                ));
            }
        }

        if ( 'none' == $this->id ) {

            $alias = $this->qualifyColumnName('id', true);
            $formula = $this->query->getRecord('id');
            $field = new Field($formula, $alias);
            $query->addField($field);

        } else {

            // Add the id field. XDMoD expects a single ID field that is always aliased to
            // sprintf('%s_id', $groupById)

            $alias = $this->qualifyColumnName('id', true);
            $formula = $this->verifyAndReplaceTableAlias($this->query->getRecord('id'), $query);
            $field = new Field($formula, $alias);
            $query->addField($field);
            $query->addGroup($field);

            // Add a GroupBy and Where condition for each field that is part of the key that maps a
            // dimensional attribute to the aggregate table.

            $mapIndex = 0;

            foreach ( $this->attributeToAggregateKeyMap as $attributeKey => $aggregateKey ) {
                $alias = $this->qualifyColumnName($attributeKey, true);
                $groupByField = new TableField(
                    $this->attributeTableObj,
                    $attributeKey,
                    $alias
                );
                $query->addGroup($groupByField);

                // The aggregation unit where condition is already added by Query::setDuration()

                if ( ! $this->isAggregationUnit ) {
                    $pieces = explode('.', $attributeKey);
                    if ( count($pieces) === 2 ) {
                        $alternateAttributeTableObj = new Table($this->attributeTableObj->getSchema(), $pieces[0], $pieces[0]);
                        $attributeKey = $pieces[1];
                    } else {
                        $alternateAttributeTableObj = null;
                    }

                    $where = new WhereCondition(
                        new TableField(
                            (
                                !empty($alternateAttributeTableObj)
                                ? $alternateAttributeTableObj
                                : $this->attributeTableObj
                            ),
                            $attributeKey
                        ),
                        '=',
                        new TableField($query->getDataTable(), $aggregateKey)
                    );
                    $query->addWhereCondition($where);
                }
            }
        }

        // Note that start_ts must be present for aggregation unit group bys

        $fieldList = array('short_name', 'name', 'order_id', 'start_ts');

        // If we find that there is an aliased column name (alias.column) in the formula, ensure
        // that the aliased table is the attribute table for this group by. Fully qualified
        // (schema.table.column) column names will be unchanged.

        foreach ($fieldList as $fieldName) {

            // Note that the formula specified as part of the attribute values query can be as
            // simple as the name of a table column (e.g., "name"), or it could contain a table
            // alias (e.g., "r.name"), or it could be a more complex formula (e.g., "CONCAT(rf.name,
            // '-', rf.code)").

            $formula = $this->query->getRecord($fieldName);
            if ( false === $formula ) {
                continue;
            }

            // TableField differs from Field in that it provides the table alias in addition to the
            // definition where Field provides only the definition. This makes TableField suitable
            // for simple values but not general SQL formulae where "CONCAT(name, '-', code)" would
            // become "table.CONCAT(name, '-', code)".  Here we use Field and include the table
            // alias ourselves.

            $alias = (
                'start_ts' != $fieldName
                ? $this->qualifyColumnName($fieldName, true)
                : sprintf('%s_%s', $query->getAggregationUnit(), $fieldName)
            );
            $finalFormula = $this->verifyAndReplaceTableAlias($formula, $query);

            $field = new Field($this->verifyAndReplaceTableAlias($formula, $query), $alias);
            $query->addField($field);
            if ($this->id !== 'none') {
                $query->addGroup($field);
            }
        }
        $this->addOrder($query);
    }

    /**
     * The formulae provided by the attribute values query can be simple column names (column),
     * aliased column names (alias.column), a fully qualified column names (schema.table.column), or
     * an SQL formula (such as CONCAT(rf.name, '-'. rf.code)). Since the attribute values query is
     * self contained, the alias used may or may not match that of the attribute table specified for
     * this group by. If an alias is specified, ensure that the table it refers to matches the
     * attribute table for this group by and change the alias to the table alias. If the formula did
     * not contain an alias, explicitly add the table alias so we do not have ambiguous columns in
     * the query.
     *
     * @param string $formula The SQL column or formula
     *
     * @return string The formula with aliases changed to the group by table alias, or with an alias
     *   added if only a column was specified.
     *
     * @throws Exception if the formula had an aliased column name but the table that it referred to
     *   did not match the group by attribute table.
     */

    protected function verifyAndReplaceTableAlias($formula)
    {
        $formula = $this->realm->getVariableStore()->substitute($formula);
        if ( 'none' == $this->id ) {
            return $formula;
        }

        $matches = array();
        if ( 0 === preg_match_all('/([a-zA-Z0-9$_]+\.)?([a-zA-Z0-9$_]+\.[a-zA-Z0-9$_]+)/', $formula, $matches, PREG_SET_ORDER) ) {
            // The formula did not contain an aliased column name, assume that it is only a column
            // name and add our table alias.
            return sprintf("%s.%s", $this->attributeTableObj->getAlias(), $formula);
        }

        foreach ( $matches as $match ) {
            if ( ! empty($match[1]) ) {
                // This is a fully qualified column name including schema, table, and column. (e.g.,
                // modw.resourcefact.code)
                continue;
            }

            // We have found a column name that includes an alias. Ensure that the table referenced
            // by the alias is the same as one of the joins or the attribute table.
            list($tableAlias, $column) = explode('.', $match[2]);
            $newTableAlias = null;
            foreach ( $this->query->joins as $joinObj ) {
                if ($tableAlias == $joinObj->name || $tableAlias == $this->getIdentifierFromTableAlias($joinObj->alias)) {
                    $newTableAlias = $joinObj->alias;
                    break;
                }
            }
            if (null === $newTableAlias) {
                $newTableAlias = $this->attributeTableObj->getAlias();
            }
            // Replace the table alias with the actual table name
            $formula = str_replace(
                $match[2],
                sprintf("%s.%s", $newTableAlias, $column),
                $formula
            );
        }

        return $formula;
    }

    /**
     * @see iGroupBy::addWhereJoin()
     * @see Query::addWhereAndJoin()
     */

    public function addWhereJoin(iQuery $query, $aggregateTableName, $operation, $whereConstraint)
    {
        // Group by none is a special case where this method is a no-op

        if ( 'none' == $this->id ) {
            return;
        }

        $attributeKeyConstraints = array();

        // JOIN with the attribute table in the query
        $query->addTable($this->attributeTableObj);

        // To support multi-column attribute keys, add JOIN conditions for each attribute and
        // aggregate column in the key map.

        foreach ( $this->attributeToAggregateKeyMap as $attributeKey => $aggregateKey ) {
            $pieces = explode('.', $attributeKey);
            if ( count($pieces) === 2 ) {
                $attributeTableField = new TableField(
                    new Table(
                        $this->attributeTableObj->getSchema(),
                        $pieces[0],
                        $this->getTableAlias($pieces[0])
                    ),
                    $pieces[1]
                );
            } else {
                $attributeTableField = new TableField($this->attributeTableObj, $attributeKey);
            }
            $aggregateTableField = new TableField($aggregateTableName, $aggregateKey);
            $where = new WhereCondition($attributeTableField, '=', $aggregateTableField);
            $this->logger->debug(sprintf('%s Add join condition: %s', $this, $where));
            $query->addWhereCondition($where);
        }

        // Normalize the WHERE constraint. We may be able to set this as an array in the parameter
        // list.

        $whereConstraint = ( ! is_array($whereConstraint) ? array($whereConstraint) : $whereConstraint );

        // Add the specified WHERE constraint. Note that to support multi-column keys the individual
        // values of the constraint may be encoded with a delimiter

        foreach ( $whereConstraint as $constraint ) {
            foreach ( $this->attributeToAggregateKeyMap as $attributeKey => $aggregateKey ) {
                $attributeKeyConstraints[$attributeKey][] = $constraint;
            }
        }

        $mapIndex = 0;

        foreach ( $attributeKeyConstraints as $attributeKey => $valueList ) {
            $where = new WhereCondition(
                sprintf(
                    '%s.%s',
                    $this->attributeTableObj->getAlias(),
                    $$attributeKey
                ),
                $operation,
                sprintf("('%s')", implode("','", $valueList))
            );
            $this->logger->debug(sprintf('%s Add where condition: %s', $this, $where));
            $query->addWhereCondition($where);
        }
    }

    /**
     * @see iGroupBy::addOrder()
     */

    public function addOrder(iQuery $query, $direction = 'ASC', $prepend = false)
    {
        // There can be zero or more order by fields specified in the attribute values query. Add an
        // order by clause for each of them.

        $queryConfig = $this->queryAsStdClass;

        if ( ! isset($queryConfig->orderby) ) {
            return;
        }

        foreach ( $queryConfig->orderby as $orderByField ) {
            $orderBy = new OrderBy(
                new Field($this->verifyAndReplaceTableAlias($orderByField)),
                $direction,
                $this->id
            );

            if ( $prepend ) {
                $this->logger->debug(sprintf('%s Prepending order by to query: %s', $this, $orderBy));
                $query->prependOrder($orderBy);
            } else {
                $this->logger->debug(sprintf('%s Appending order by to query: %s', $this, $orderBy));
                $query->addOrder($orderBy);
            }
        }
    }

    /**
     * @see iGroupBy::getAttributeValues()
     */

    public function getAttributeValues(array $restrictions = null)
    {
        $whereConditions = array();
        $queryParameters = array();

        // Use the attribute values query as a template so we don't modify the original object
        $queryConfig = $this->query->toStdClass();

        // To handle possible ambiguous columns in the attribute values query, be sure to alias the
        // where conditions with the attribute table (first table).

        $alias = (isset($queryConfig->joins[0]->alias) ? $queryConfig->joins[0]->alias : $this->attributeTableObj->getAlias());

        if ( isset($restrictions['id']) ) {
            foreach ( array_keys($this->attributeToAggregateKeyMap) as $attributeKey ) {
                $whereConditions[] = sprintf('(%s.%s = :%s)', $alias, $attributeKey, $attributeKey);
                $queryParameters[ sprintf(':%s', $attributeKey) ] = $restrictions['id'];
            }
        }

        if ( isset($restrictions['name']) ) {
            $whereConditions[] = sprintf(
                '(%s LIKE :name OR %s LIKE :name)',
                $this->query->getRecord('short_name'),
                $this->query->getRecord('name')
            );
            $queryParameters[':name'] = $restrictions['name'];
        }

        if ( ! isset($queryConfig->where) || ! is_array($queryConfig->where) ) {
            $queryConfig->where = $whereConditions;
        } else {
            $queryConfig->where = array_merge($queryConfig->where, $whereConditions);
        }

        $queryObj = new DbQuery($queryConfig, '`', $this->logger);
        $sql = $queryObj->getSql();

        $this->logger->debug(sprintf("%s: Fetch attribute values with query\n%s", $this, $sql));

        return \DataWarehouse::connect()->query($sql, $queryParameters);
    }

    /**
     * @see iGroupBy::getQuery()
     */

    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Static accessors used to create usage chart settings in DataWarehouse/Access/Usage.php and
     * DataWarehouse/Query/GroupBy.php
     */

    /**
     * @see iGroupBy::getChartSettings()
     */

    public function getChartSettings($isMultiChartPage = false)
    {
        return json_encode(
            array(
                'dataset_type' => $this->getDefaultDatasetType(),
                'display_type' => $this->getDefaultDisplayType($this->getDefaultDatasetType()),
                'combine_type' => $this->getDefaultCombineMethod(),
                'limit' => $this->getDefaultLimit($isMultiChartPage),
                'offset' => $this->getDefaultOffset(),
                'log_scale' => $this->getDefaultLogScale(),
                'show_legend' => $this->getDefaultShowLegend(),
                'show_trend_line' => $this->getDefaultShowTrendLine(),
                'show_error_bars' => $this->getDefaultShowErrorBars(),
                'show_guide_lines' => $this->getDefaultShowGuideLines(),
                'show_aggregate_labels' => $this->getDefaultShowAggregateLabels(),
                'show_error_labels' => $this->getDefaultShowErrorLabels(),
                'enable_errors' => $this->getDefaultEnableErrors(),
                'enable_trend_line' => $this->getDefaultEnableTrendLine(),
            )
        );
    }

    /**
     * @see iGroupBy::getDefaultDatasetType()
     */

    public function getDefaultDatasetType()
    {
        return $this->chartOptions['dataset_type'];
    }

    /**
     * @see iGroupBy::getDefaultDisplayType()
     */

    public function getDefaultDisplayType($dataset_type = 'timeseries')
    {
        if ( ! array_key_exists($dataset_type, $this->chartOptions['dataset_display_type']) ) {
            $this->logAndThrowException(sprintf("Unsupported dataset type: '%s'", $dataset_type));
        }
        return $this->chartOptions['dataset_display_type'][$dataset_type];
    }

    /**
     * @see iGroupBy::getDefaultCombineMethod()
     */

    public function getDefaultCombineMethod()
    {
        return $this->chartOptions['combine_method'];
    }

    /**
     * @see iGroupBy::getDefaultShowLegend()
     */

    public function getDefaultShowLegend()
    {
        return $this->chartOptions['show_legend'];
    }

    /**
     * @see iGroupBy::getDefaultLimit()
     */

    public function getDefaultLimit($isMultiChartPage = false)
    {
        $pageType = ( $isMultiChartPage ? 'multichart_page' : 'default' );
        return $this->chartOptions['limit'][$pageType];
    }

    /**
     * @see iGroupBy::getDefaultOffset()
     */

    public function getDefaultOffset()
    {
        return $this->chartOptions['offset'];
    }

    /**
     * @see iGroupBy::getDefaultLogScale()
     */

    public function getDefaultLogScale()
    {
        return $this->chartOptions['log_scale'];
    }

    /**
     * @see iGroupBy::getDefaultShowTrendLine()
     */

    public function getDefaultShowTrendLine()
    {
        return $this->chartOptions['show_trend_line'];
    }

    /**
     * @see iGroupBy::getDefaultShowErrorBars()
     */

    public function getDefaultShowErrorBars()
    {
        return $this->chartOptions['show_error_bars'];
    }

    /**
     * @see iGroupBy::getDefaultShowGuideLines()
     */

    public function getDefaultShowGuideLines()
    {
        return $this->chartOptions['show_guide_lines'];
    }

    /**
     * @see iGroupBy::getDefaultShowAggregateLabels()
     */

    public function getDefaultShowAggregateLabels()
    {
        return $this->chartOptions['show_aggregate_labels'];
    }

    /**
     * @see iGroupBy::getDefaultShowErrorLabels()
     */

    public function getDefaultShowErrorLabels()
    {
        return $this->chartOptions['show_error_labels'];
    }

    /**
     * @see iGroupBy::getDefaultEnableErrors()
     */

    public function getDefaultEnableErrors()
    {
        return $this->chartOptions['enable_errors'];
    }

    /**
     * @see iGroupBy::getDefaultEnableTrendLine()
     */

    public function getDefaultEnableTrendLine()
    {
        return $this->chartOptions['enable_trend_line'];
    }

    /**
     * @see iGroupBy::getCategory()
     */

    public function getCategory()
    {
        return $this->category;
    }

    /**
     * @see iGroupBy::showAllDimensionValues()
     */

    public function showAllDimensionValues()
    {
        return $this->showAllDimensionValues;
    }

    private function getTableAlias($tableName)
    {
        if (null === $tableName) {
            return null;
        }
        return $this->id . '__' . $tableName;
    }

    private function getIdentifierFromTableAlias($alias)
    {
        if (iQuery::DATE_TABLE_ALIAS == $alias) {
            return $alias;
        }
        return explode('__', $alias)[1];
    }

    /**
     * @see iGroupBy::__toString()
     */

    public function __toString()
    {
        return sprintf('Realm(%s)->GroupBy(id=%s)', $this->realm->getId(), $this->id);
    }
}
