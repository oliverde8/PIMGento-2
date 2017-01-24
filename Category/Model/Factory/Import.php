<?php

namespace Pimgento\Category\Model\Factory;

use Magento\Staging\Model\VersionManager;
use \Pimgento\Import\Model\Factory;
use \Pimgento\Entities\Model\Entities;
use \Pimgento\Import\Helper\Config as helperConfig;
use \Pimgento\Staging\Helper\Config as StagingConfigHelper;
use \Pimgento\Staging\Helper\Import as StagingHelper;
use \Pimgento\Import\Helper\UrlRewrite as urlRewriteHelper;
use \Magento\Framework\Event\ManagerInterface;
use \Magento\Catalog\Model\Category;
use \Magento\Framework\App\Cache\TypeListInterface;
use \Magento\Framework\Module\Manager as moduleManager;
use \Magento\Framework\App\Config\ScopeConfigInterface as scopeConfig;
use \Zend_Db_Expr as Expr;

class Import extends Factory
{

    /**
     * @var Entities
     */
    protected $_entities;

    /**
     * @var Category
     */
    protected $_category;

    /**
     * @var TypeListInterface
     */
    protected $_cacheTypeList;

    /**
     * @var urlRewriteHelper
     */
    protected $_urlRewriteHelper;

    /**
     * @var StagingHelper
     */
    protected $stagingHelper;

    /**
     * @var StagingConfigHelper
     */
    protected $stagingConfigHelper;

    /**
     * @param \Pimgento\Entities\Model\Entities $entities
     * @param \Pimgento\Import\Helper\Config $helperConfig
     * @param \Magento\Framework\Module\Manager $moduleManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Catalog\Model\Category $category
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param urlRewriteHelper $urlRewriteHelper
     * @param StagingConfigHelper $stagingConfigHelper
    *  @param StagingHelper $stagingHelper
     * @param array $data
     */
    public function __construct(
        Entities $entities,
        helperConfig $helperConfig,
        moduleManager $moduleManager,
        scopeConfig $scopeConfig,
        ManagerInterface $eventManager,
        Category $category,
        TypeListInterface $cacheTypeList,
        urlRewriteHelper $urlRewriteHelper,
        StagingConfigHelper $stagingConfigHelper,
        StagingHelper $stagingHelper,
        array $data = []
    )
    {
        parent::__construct($helperConfig, $eventManager, $moduleManager, $scopeConfig, $data);
        $this->_entities = $entities;
        $this->_category = $category;
        $this->_cacheTypeList = $cacheTypeList;
        $this->_urlRewriteHelper = $urlRewriteHelper;
        $this->stagingConfigHelper = $stagingConfigHelper;
        $this->stagingHelper = $stagingHelper;
    }

    /**
     * Create temporary table
     */
    public function createTable()
    {
        $file = $this->getFileFullPath();

        if (!is_file($file)) {
            $this->setContinue(false);
            $this->setStatus(false);
            $this->setMessage($this->getFileNotFoundErrorMessage());
        } else {
            $this->_entities->createTmpTableFromFile($file, $this->getCode(), array('code', 'parent'));
        }
    }

    /**
     * Add required columns
     */
    public function addRequiredData()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $this->stagingHelper->addRequiredData($connection, $tmpTable);
    }

    /**
     * Insert data into temporary table
     */
    public function insertData()
    {
        $file = $this->getFileFullPath();

        $count = $this->_entities->insertDataFromFile($file, $this->getCode());

        $this->setMessage(
            __('%1 line(s) found', $count)
        );
    }

    /**
     * Match code with entity
     */
    public function matchEntity()
    {
        if ($this->stagingConfigHelper->isCatalogStagingModulesEnabled()) {
            /**
             * When using staging module entity id's are not the primary key of the catalog_product_entity
             * table anymore. The new primary keys is row_id. Before we get information on the row_id, we still
             * need to get the entiy_id of the products to be imported. We are therefore going to use a different
             * table built for this purpose in magento.
             */
            $this->_entities->matchEntity($this->getCode(), 'code', 'sequence_catalog_category', 'sequence_value');

            // Once the entitie id's are matched we can match the row ids.
            $this->stagingHelper->matchEntityRows(
                $this->_entities,
                'catalog_category_entity',
                $this->getCode(),
                StagingConfigHelper::STAGING_MODE_LAST
            );

        } else {
            $this->_entities->matchEntity($this->getCode(), 'code', 'catalog_category_entity', 'entity_id');
        }
    }

    /**
     * Set categories Url Key
     */
    public function setUrlKey()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $stores = $this->_helperConfig->getStores('lang');

        foreach ($stores as $local => $affected) {
            if ($connection->tableColumnExists($tmpTable, 'label-' . $local)) {

                $connection->addColumn($tmpTable, 'url_key-' . $local, 'VARCHAR(255) NOT NULL DEFAULT ""');

                $query = $connection->query(
                    $connection->select()
                        ->from($tmpTable, array('entity_id' => '_entity_id', 'name' => 'label-' . $local))
                );

                while (($row = $query->fetch())) {
                    $urlKey = $this->_category->formatUrlKey($row['name']);

                    $connection->update(
                        $tmpTable, array('url_key-' . $local => $urlKey), array('_entity_id = ?' => $row['entity_id'])
                    );
                }
            }
        }
    }

    /**
     * Set Categories structure
     */
    public function setStructure()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $connection->addColumn($tmpTable, 'level', 'INT(11) NOT NULL DEFAULT 0');
        $connection->addColumn($tmpTable, 'path', 'VARCHAR(255) NOT NULL DEFAULT ""');
        $connection->addColumn($tmpTable, 'parent_id', 'INT(11) NOT NULL DEFAULT 0');

        $stores = $this->_helperConfig->getStores('lang');

        $values = array(
            'level'     => 1,
            'path'      => new Expr('CONCAT(1, "/", `_entity_id`)'),
            'parent_id' => 1,
        );
        $connection->update($tmpTable, $values, 'parent = ""');

        $updateRewrite = array();

        foreach ($stores as $local => $affected) {
            if ($connection->tableColumnExists($tmpTable, 'url_key-' . $local)) {
                $connection->addColumn($tmpTable, '_url_rewrite-' . $local, 'VARCHAR(255) NOT NULL DEFAULT ""');
                $updateRewrite[] = 'c1.`_url_rewrite-' . $local . '` =
                    TRIM(BOTH "/" FROM CONCAT(c2.`_url_rewrite-' . $local . '`, "/", c1.`url_key-' . $local . '`))';
            }
        }

        $depth = 10;
        for ($i = 1; $i <= $depth; $i++) {
            $connection->query('
                UPDATE `' . $tmpTable . '` c1
                INNER JOIN `' . $tmpTable . '` c2 ON c2.`code` = c1.`parent`
                SET ' . (!empty($updateRewrite) ? join(',', $updateRewrite) . ',' : '') . '
                    c1.`level` = c2.`level` + 1,
                    c1.`path` = CONCAT(c2.`path`, "/", c1.`_entity_id`),
                    c1.`parent_id` = c2.`_entity_id`
                WHERE c1.`level` <= c2.`level` - 1
            ');
        }
    }

    /**
     * Set categories position
     */
    public function setPosition()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $connection->addColumn($tmpTable, 'position', 'INT(11) NOT NULL DEFAULT 0');

        $query = $connection->query(
            $connection->select()
                ->from(
                    $tmpTable,
                    array(
                        'entity_id' => '_entity_id',
                        'parent_id' => 'parent_id',
                    )
                )
        );

        while (($row = $query->fetch())) {
            $position = $connection->fetchOne(
                $connection->select()
                    ->from(
                        $tmpTable,
                        array(
                            'position' => new Expr('MAX(`position`) + 1')
                        )
                    )
                    ->where('parent_id = ?', $row['parent_id'])
                    ->group('parent_id')
            );
            $values = array(
                'position' => $position
            );
            $connection->update($tmpTable, $values, array('_entity_id = ?' => $row['entity_id']));
        }
    }

    /**
     * Create category entities
     */
    public function createEntities()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $values = array(
            'entity_id'        => '_entity_id',
            'attribute_set_id' => new Expr(3),
            'parent_id'        => 'parent_id',
            'updated_at'       => new Expr('now()'),
            'path'             => 'path',
            'position'         => 'position',
            'level'            => 'level',
            'children_count'   => new Expr('0'),
        );

        if ($this->stagingConfigHelper->isCatalogStagingModulesEnabled()) {
            $values['created_in'] =  'created_in';
            $values['updated_in'] = 'updated_in';
            $values['row_id'] = '_row_id';
        }

        $this->stagingHelper->createEntitiesBefore($connection, 'sequence_catalog_category', $tmpTable);

        $parents = $connection->select()->from($tmpTable, $values);
        $connection->query(
            $connection->insertFromSelect(
                $parents,
                $connection->getTableName('catalog_category_entity'),
                array_keys($values),
                1
            )
        );

        $this->stagingHelper->createEntitiesAfter($connection, 'catalog_category_entity', $tmpTable);

        $values = array(
            'created_at' => new Expr('now()')
        );
        $connection->update($connection->getTableName('catalog_category_entity'), $values, 'created_at IS NULL');
    }

    /**
     * Set values to attributes
     */
    public function setValues()
    {
        $connection = $this->_entities->getResource()->getConnection();
        $tmpTable = $this->_entities->getTableName($this->getCode());

        $values = array(
            'is_active'       => new Expr(1),
            'include_in_menu' => new Expr(1),
            'is_anchor'       => new Expr(1),
            'display_mode'    => new Expr('"PRODUCTS"'),
        );

        $identifierField =  $this->stagingConfigHelper->isCatalogStagingModulesEnabled() ? 'row_id' : 'entity_id';

        $this->_entities->setValues(
            $this->getCode(), $connection->getTableName('catalog_category_entity'), $values, 3, 0, 2, $identifierField
        );

        $stores = $this->_helperConfig->getStores('lang');

        foreach ($stores as $local => $affected) {
            if ($connection->tableColumnExists($tmpTable, 'label-' . $local)) {
                foreach ($affected as $store) {
                    $values = array(
                        'name'    => 'label-' . $local,
                        'url_key' => 'url_key-' . $local,
                    );
                    $this->_entities->setValues(
                        $this->getCode(),
                        $connection->getTableName('catalog_category_entity'),
                        $values,
                        3,
                        $store['store_id'],
                        1,
                        $identifierField
                    );
                }
            }
        }
    }

    /**
     * Update Children Count
     */
    public function updateChildrenCount()
    {
        $connection = $this->_entities->getResource()->getConnection();

        $connection->query('
            UPDATE `' . $connection->getTableName('catalog_category_entity') . '` c SET `children_count` = (
                SELECT COUNT(`parent_id`) FROM (
                    SELECT * FROM `' . $connection->getTableName('catalog_category_entity') . '`
                ) tmp
                WHERE tmp.`path` LIKE CONCAT(c.`path`,\'/%\')
            )
        ');
    }

    /**
     * Set Url Rewrite
     */
    public function setUrlRewrite()
    {
        $connection   = $this->_entities->getResource()->getConnection();
        $tmpTable     = $this->_entities->getTableName($this->getCode());

        $stores = $this->_helperConfig->getStores('lang');
        $this->_urlRewriteHelper->createUrlTmpTable();

        foreach ($stores as $local => $affected) {

            $column = '_url_rewrite-' . $local;
            if ($connection->tableColumnExists($tmpTable, $column)) {
                foreach ($affected as $store) {

                    if ($store['store_id'] == 0) {
                        continue;
                    }

                    $this->_urlRewriteHelper->rewriteUrls($this->getCode(), $store['store_id'], $column);
                }
            }

        }

        $this->_urlRewriteHelper->dropUrlRewriteTmpTable();
    }

    /**
     * Drop temporary table
     */
    public function dropTable()
    {
        $this->_entities->dropTable($this->getCode());
    }

    /**
     * Clean cache
     */
    public function cleanCache()
    {
        $types = array(
            \Magento\Framework\App\Cache\Type\Block::TYPE_IDENTIFIER,
            \Magento\PageCache\Model\Cache\Type::TYPE_IDENTIFIER
        );

        foreach ($types as $type) {
            $this->_cacheTypeList->cleanType($type);
        }

        $this->setMessage(
            __('Cache cleaned for: %1', join(', ', $types))
        );
    }

}