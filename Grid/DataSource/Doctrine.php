<?php
/**
 * Copyright (C) 2011 by Pieter Vogelaar (pietervogelaar.nl) and Kees Schepers (keesschepers.nl)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

/**
 * Data source for Doctrine queries and entities. You can use this data source with
 * Pike_Grid which will both create all neccasary javascript and JSON for drawing
 * a grid with JQgrid.
 *
 * Dependecies: jqGrid, Doctrine, Zend Framework
 *
 * @category   PiKe
 * @copyright  Copyright (C) 2011 by Pieter Vogelaar (pietervogelaar.nl) and Kees Schepers (keesschepers.nl)
 * @license    MIT
 */
class Pike_Grid_DataSource_Doctrine
    extends Pike_Grid_DataSource_Abstract
    implements Pike_Grid_DataSource_Interface
{
    /**
     * @var $_query Doctrine\ORM\Query
     */
    protected $_query;

    /**
     * Constructor
     *
     * @param mixed $source
     */
    public function __construct($source)
    {
        parent::__construct();

        switch($source) {
            case ($source instanceof Doctrine\ORM\QueryBuilder) :
                $this->_query = $source->getQuery();
                break;
            case ($source instanceof Doctrine\ORM\Query) :
                 $this->_query = $source;
                break;
            case ($source instanceof \Doctrine\ORM\EntityRepository) :
                $this->_query = $source->createQueryBuilder('al')->getQuery();
                break;
            default :
                throw new Pike_Exception('Unknown source given, source must either be an entity, query or querybuilder object.');
                break;
        }

        $this->_setColumns();
        $this->_initEvents();
    }

    /**
     * Initializes default behavior for sorting, filtering, etc.
     *
     * @return void
     */
    private function _initEvents()
    {
        $onOrder = function(array $params, Pike_Grid_DataSource_Interface $dataSource) {
            $columns = $dataSource->columns->getAll();

            $sidx = $dataSource->getSidx($params['sidx']);

            $sord = (in_array(strtoupper($params['sord']), array('ASC', 'DESC')) ? strtoupper($params['sord']) : 'ASC');

            return array(
                \Doctrine\ORM\Query::HINT_CUSTOM_TREE_WALKERS => array('Pike_Grid_DataSource_Doctrine_OrderByWalker'),
                'sidx' => $sidx,
                'sord' => $sord,
            );
        };

        $this->_onOrder = $onOrder;

        $onFilter = function(array $params, Pike_Grid_DataSource_Interface $dataSource) {
            $filters = json_decode($params['filters']);
            foreach ($filters->rules as $index => $rule) {
                $fieldName = $dataSource->getFieldName($rule->field);
                if ($fieldName) {
                    $rule->field = $fieldName;
                } else {
                    unset($filters->rules[$index]);
                }
            }

            return array(
                \Doctrine\ORM\Query::HINT_CUSTOM_TREE_WALKERS => array('Pike_Grid_DataSource_Doctrine_WhereLikeWalker'),
                'operator' => $filters->groupOp,
                'fields' => $filters->rules,
            );
        };

        $this->_onFilter = $onFilter;
    }

    /**
     * Looks up in the AST what select expression we use and analyses which
     * fields are used. This is passed thru the datagrid for displaying fieldnames.
     *
     * @return array
     */
    private function _setColumns()
    {
        $this->columns = new Pike_Grid_DataSource_Columns();

        $selectClause = $this->_query->getAST()->selectClause;
        if (count($selectClause->selectExpressions) == 0) {
            throw new Pike_Exception('The grid query should contain at least one column, none found.');
        }

        /* @var $selExpr Doctrine\ORM\Query\AST\SelectExpression */
        foreach ($selectClause->selectExpressions as $selExpr) {

            /**
             * Some magic needs to happen here. If $selExpr isn't a instanceof
             * Doctrine\ORM\Query\AST\PathExpression then it might be a custom
             * expression like a vendor specific function. We could use the
             * $selExpr->fieldIdentificationVariable member which is the alias
             * given to the special expression but sorting at this field
             * could cause strange effects.
             *
             * For instance: SELECT DATE_FORMAT(datefield,"%Y") AS alias FROM sometable
             *
             * When you say: ORDER BY alias DESC
             *
             * You could get other results when you do:
             *
             * ORDER BY sometable.datefield DESC
             *
             * My idea is to rely on the alias field by default because it would
             * suite for most queries. If someone would like to retrieve this expression
             * specific info they can add a field filter. $ds->addFieldFilter(new DateFormat_Field_Filter(), 'expression classname');
             *
             * And info of the field would be extracted from this class, something like that.
             */

            $expr = $selExpr->expression;

            /* @var $expr Doctrine\ORM\Query\AST\PathExpression */
            if ($expr instanceof Doctrine\ORM\Query\AST\PathExpression) {
                // Check if field alias is available
                if (null !== $selExpr->fieldIdentificationVariable) {
                    // Set field alias as column name
                    $name = $selExpr->fieldIdentificationVariable;

                    // Set field alias as label
                    $label = $selExpr->fieldIdentificationVariable;
                } else {
                    // Set field name as column name
                    $name = $expr->field;

                    // Set field name as label
                    $label = $expr->field;
                }

                $sidx = $name;
            } else {
                $name = $selExpr->fieldIdentificationVariable;
                $label = $name;
                $sidx = null;
            }

            $this->columns->add($name, $label, $sidx);
        }
    }

    /**
     * Defines what happends when the grid is sorted by the server. Must return a array
     * with query hints!
     *
     * @return array
     */
    public function setEventSort(Closure $function)
    {
        $this->_onOrder = $function;
    }

    /**
     * Defines what happends when the user filters data with jqGrid and send to the server. Must
     * return an array with query hints!
     *
     * @return array
     */
    public function setEventFilter(Closure $function)
    {
        $this->_onFilter = $function;
    }

    /**
     * Looks if there is default sorting defined in the original query by asking the AST. Defining
     * default sorting is done outside the data source where query or querybuilder object is defined.
     *
     * @return array
     */
    public function getDefaultSorting()
    {
        if (null !== $this->_query->getAST()->orderByClause) {
           //support for 1 field only
            $orderByClause = $this->_query->getAST()->orderByClause;

            /* @var $orderByItem Doctrine\ORM\Query\AST\OrderByItem */
            $orderByItem = $orderByClause->orderByItems[0];

            if ($orderByItem->expression instanceof \Doctrine\ORM\Query\AST\PathExpression) {
                $data['index'] = $orderByItem->expression->field;
                $data['direction'] = $orderByItem->type;

                return $data;
            }
        }

        return null;
    }

    /**
     * Returns the initialized query hints based on the user-interface jqgrid parameters
     *
     * @return array Doctrine compatible hints array
     */
    private function _getQueryHints()
    {
        $hints = array();

        // Set sorting if defined
        if (array_key_exists('sidx', $this->_params) && strlen($this->_params['sidx']) > 0
            && $this->getSidx($this->_params['sidx'])
        ) {
            $onSort = $this->_onOrder;
            $hints = array_merge_recursive($hints, $onSort($this->_params, $this));
        }

        if (array_key_exists('filters', $this->_params) && (array_key_exists('_search', $this->_params)
            && $this->_params['_search'] == true)) {

            $onFilter = $this->_onFilter;
            $hints = array_merge_recursive($hints, $onFilter($this->_params, $this));
        }

        return $hints;
    }

    /**
     * Returns a JSON string useable for JQuery Grid. This grids interprets this
     * data and is able to draw a grid.
     *
     * @param boolean $encode         If data must be JSON encoded. If false, you must encode the returned
     *                                data yourself through a JSON encode function.
     * @param array   $excludeColumns If you have columns in your query with the only purpose of
     *                                suplementing data for the construction of other columns and
     *                                you also probably set the column as hidden and you don't need
     *                                their value on the client side for manipulation, then you can
     *                                exclude the columns here to gain some performance.
     * @return string JSON data
     */
    public function getJson($encode = true, array $excludeColumns = array())
    {
        $offset = $this->_limitPerPage * ($this->_params['page'] - 1);
        $hints = $this->_getQueryHints();

        $count = Pike_Grid_DataSource_Doctrine_Paginate::getTotalQueryResults($this->_query, $hints);

        $paginateQuery = Pike_Grid_DataSource_Doctrine_Paginate::getPaginateQuery(
            $this->_query,
            $offset,
            $this->_limitPerPage,
            $hints
        );

        $result = $paginateQuery->getResult();

        $this->_data = array();
        $this->_data['page'] = (int)$this->_params['page'];
        $this->_data['total'] = ceil($count / $this->_limitPerPage);
        $this->_data['records'] = $count;
        $this->_data['rows'] = array();

        foreach ($result as $row) {
            $this->_renderRow($row, $excludeColumns);
        }

        return $encode === true ? json_encode($this->_data) : $this->_data;
    }

    /**
     * Returns the actual field name for the specified column name (field alias,
     * actual field name, or sidx)
     *
     * @param  string $sidx
     * @return string|boolean
     */
    public function getSidx($sidx)
    {
        if (strpos($sidx, '.') === false) {
            $sidx = $this->getFieldName($sidx);
        }
        return $sidx;
    }

    /**
     * Returns the actual field name for the specified column name (field alias or actual field name)
     *
     * The field alias is used if available, otherwise the composition
     * of "identification variable" and "field".
     *
     * @param  string $alias
     * @return string|boolean
     */
    public function getFieldName($alias)
    {
        $field = false;

        /* @var $selExpr Doctrine\ORM\Query\AST\SelectExpression */
        foreach ($this->_query->getAST()->selectClause->selectExpressions as $selExpr) {
            $expr = $selExpr->expression;

            // Skip aggregate fields
            if (!isset($expr->field)) {
                continue;
            }

            // Check if field alias exists
            if ($alias == $selExpr->fieldIdentificationVariable) {
                $field = $expr->identificationVariable . '.' . $expr->field;
                break;
            } elseif (isset($expr->field) && $alias == $expr->field && '' == $selExpr->fieldIdentificationVariable) {
                $field = $expr->identificationVariable . '.' . $expr->field;
            }
        }

        return $field;
    }

    /**
     * @return Doctrine\ORM\Query
     */
    public function getQuery()
    {
        return $this->_query;
    }
}