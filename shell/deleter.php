<?php
/**
 * Magento Product (in future also category) Deleter
 *
 * @category Agere
 * @package Agere_Shell
 * @author Popov Sergiy <popov@agere.com.ua>
 * @datetime: 22.12.15 12:20
 */
$rootPath = realpath($file = './');

require_once $rootPath . '/shell/abstract.php';

class Mage_Shell_Deleter extends Mage_Shell_Abstract
{
    public function run()
    {
        /** Magento Import/Export Profiles */
        if ($deleteType = $this->getArg('delete')) {
            if (method_exists($this, $method = $deleteType . 'Delete')) {
                $this->{$method}();
            }
        } elseif ($filterType = $this->getArg('filter')) {
            $this->byFilterDelete();
        } else {
            echo $this->usageHelp();
        }
    }

    /**
     * Retrieve Usage Help Message
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:  php deleter.php -- [options]

  --delete <type>            Available types:
                                - disabled - delete disabled products
                                - noImage - delete products without image
                                - rewritePermanent - delete all permanent rewrite
                                - #rewrite - delete all rewrite
                                - all - include all prev types

  --filter <attrName:magentoCondition:attrValue>
                            - attrName - name of attribute
                            - magentoCondition - http://fishpig.co.uk/magento/tutorials/addattributetofilter
                            - attrValue - attribute value
                            Example:
                                sku:neq:test-product
                                sku:nlike:err-prod%
                                entity_id:in:1,4,98
                                description:null:true
                            Available any set of filter demarcated by semicolon (;)

USAGE;
    }

    public function allDelete()
    {
        $this->disabledDelete();
        $this->noImageDelete();
        $this->rewriteDelete();
    }

    /**
     * Delete disabled products
     *
     * @link http://magento.stackexchange.com/a/95584
     * @return bool
     */
    public function disabledDelete()
    {
        //$connectionRead = Mage::getSingleton('core/resource')->getConnection('core_read');
        $connectionWrite = Mage::getSingleton('core/resource')->getConnection('core_write');

        $sql = <<<SQL
DELETE e.* FROM catalog_product_entity e
	INNER JOIN catalog_product_entity_int v
	ON v.entity_id = e.entity_id
	AND v.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'status')
	WHERE v.value = 2;
SQL;
        $message = ($bool = $connectionWrite->query($sql))
            ? 'Disabled products successfully delete!'
            : 'Cannot delete disabled products!';

        echo $message . "\r\n";

        return $bool;
    }

    /**
     * Delete products without image
     *
     * @return bool
     */
    protected function noImageDelete()
    {
        //$connectionRead = Mage::getSingleton('core/resource')->getConnection('core_read');
        $connectionWrite = Mage::getSingleton('core/resource')->getConnection('core_write');

        $sql = <<<SQL
-- here you set every one as DISABLED (id 2)
UPDATE catalog_product_entity_int SET value = 2
-- here you are change just the attribute STATUS
WHERE attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'status')
    -- here you are looking for the products that match your criteria
    AND entity_id IN (
        -- your original search
        SELECT catalog_product_entity.entity_id
            FROM catalog_product_entity_media_gallery
            RIGHT OUTER JOIN catalog_product_entity
            ON catalog_product_entity.entity_id = catalog_product_entity_media_gallery.entity_id
            WHERE catalog_product_entity_media_gallery.value IS NULL);
SQL;

        $message = ($bool = $connectionWrite->query($sql))
            ? 'Products without image successfully delete!'
            : 'Cannot delete products without image!';

        echo $message . "\r\n";

        return $bool;
    }

    protected function rewriteDelete()
    {
        $write = Mage::getSingleton('core/resource')->getConnection('core_write');
        try {
            $table = Mage::getSingleton('core/resource')->getTableName('core/url_rewrite');
            $count = $write->exec('TRUNCATE TABLE ' . $table);
            $message = 'Successfully removed redirects.';
        } catch(Exception $e) {
            $message = "An error occurred while clearing url redirects: " . $e->getMessage();
        }

        echo $message . "\r\n";

        return (bool) $count;
    }

    /**
     * @link http://stackoverflow.com/a/35711673/1335142
     * Delete all permanent redirects
     */
    protected function rewritePermanentDelete()
    {
        $write = Mage::getSingleton('core/resource')->getConnection('core_write');
        try {
            $write->beginTransaction();
            $table = Mage::getSingleton('core/resource')->getTableName('core/url_rewrite');
            $count = $write->exec('DELETE FROM ' . $table . ' WHERE options IS NOT NULL AND is_system = 0');
            $write->commit();
            //$message = $this->__('Successfully removed %s redirects.', $count);
            $message = 'Successfully removed redirects.';
        } catch(Exception $e) {
            $write->rollback();
            //$message = $this->__("An error occurred while clearing url redirects: %s", $e->getMessage());
            $message = "An error occurred while clearing url redirects: " . $e->getMessage();
        }

        echo $message . "\r\n";

        return (bool) $count;
    }

    /**
     * Delete product by attribute
     * @link https://www.sonassi.com/blog/magento-kb/mass-delete-products-in-magento
     */
    protected function byFilterDelete()
    {
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $products = Mage::getModel('catalog/product')->getCollection();

        if (!$this->applyFilters($products)) {
            echo 'Cannot parse any filter. Please, check correct spelling.' . "\r\n";

            return;
        }

        $sql = "";
        $undoSql = "";
        for ($i = 0; $i <= 8; $i++) {
            $sql .= "UPDATE index_process SET mode = 'manual' WHERE index_process.process_id ={$i} LIMIT 1;";
            $undoSql .= "UPDATE index_process SET mode = 'real_time' WHERE index_process.process_id ={$i} LIMIT 1;";
        }

        $mysqli = Mage::getSingleton('core/resource')->getConnection('core_write');
        $mysqli->query($sql);
        $totalProducts = count($products);
        $count = 0;
        $time = 0;
        foreach($products as $product) {
            $product->delete();
            if ($count++ % 100 == 0) {
                $cur = strtotime(date('d/m/y h:i:s')) - $time;
                $time = strtotime(date('d/m/y h:i:s'));
                echo round((($count / $totalProducts) * 100), 2) . "% deleted ({$count}/{$totalProducts}) " . round(100 / $cur) . ' p/s ' . date('H:i:s') . "\r\n";
                flush();
            }
        }

        echo 'Ended ' . date('d/m/Y H:i:s') . "\r\n";
        $mysqli->query($undoSql);
    }

    protected function applyFilters($collection)
    {
        $filters = explode(';', $this->getArg('filter'));

        $hasFilters = false;
        foreach ($filters as $filter) {
            $parts = explode(':', $filter);
            if (3 === count($parts)) {
                $hasFilters = true;

                $attributeName = $parts[0];
                $condition = $parts[1];
                $value = ($condition == 'in') ? explode(',', $parts[2]) : $parts[2];
                if (in_array($value, array('true', 'false'))) {
                    $value = ($value === 'true') ? true : false;
                }

                $collection->addAttributeToFilter($attributeName, array($condition => $value));
                // ->addFieldToFilter('attribute_set_id', $attributeSetId);
                // ->addAttributeToFilter('status', array('eq' =>1))
            }
        }

        return $hasFilters;
    }
}

$shell = new Mage_Shell_Deleter();
$shell->run();
