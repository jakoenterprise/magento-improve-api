<?php

class Bubble_Api_Helper_Catalog_Product extends Mage_Core_Helper_Abstract
{
    const CATEGORIES_SEPARATOR_PATH_XML = 'bubble_api/config/categories_separator';

    /**
     * @param Mage_Catalog_Model_Product $product
     * @param array $simpleSkus
     * @param array $priceChanges
     * @return Bubble_Api_Helper_Catalog_Product
     */
    public function associateProducts(Mage_Catalog_Model_Product $product, $simpleSkus, $priceChanges = array(), $configurableAttributes = array(), $add = False)
    {
        if (!empty($simpleSkus)) {
            $newProductIds = Mage::getModel('catalog/product')->getCollection()
                ->addFieldToFilter('sku', array('in' => (array) $simpleSkus))
                ->addFieldToFilter('type_id', Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
                ->getAllIds();

            if ($add) {
                $oldProductIds = Mage::getModel('catalog/product_type_configurable')->setProduct($product)->getUsedProductCollection()
                    ->addAttributeToSelect('*')
                    ->addFilterByRequiredOptions()
                    ->getAllIds();
                $usedProductIds = array_unique(array_merge($newProductIds, $oldProductIds));
            } else {
                $usedProductIds = array_unique($newProductIds);
            }

            if (!empty($newProductIds) && $product->isConfigurable()) {
                $this->_initConfigurableAttributesData($product, $newProductIds, $priceChanges, $configurableAttributes);
            }

            if (!empty($usedProductIds) && $product->isGrouped()) {
                $relations = array_fill_keys($usedProductIds, array('qty' => 0, 'position' => 0));
                $product->setGroupedLinkData($relations);
            }
        }

        return $this;
    }

    /**
     * @param array $categoryNames
     * @return array
     */
    public function getCategoryIdsByNames($categoryNames)
    {
        $categories = array();
        $separator = $this->_getCatagoriesSeparator();
        foreach ($categoryNames as $category) {
            if (is_string($category) && !is_numeric($category)) {
                $pieces = explode($separator, $category);
                $addCategories = array();
                $parentIds = array();
                foreach ($pieces as $level => $name) {
                    $collection = Mage::getModel('catalog/category')->getCollection()
                        ->setStoreId(0)
                        ->addFieldToFilter('level', $level + 2)
                        ->addAttributeToFilter('name', $name);
                    if (!empty($parentIds)) {
                        $collection->getSelect()->where('parent_id IN (?)', $parentIds);
                    }
                    $parentIds = array();
                    if ($collection->count()) {
                        foreach ($collection as $category) {
                            $addCategories[] = (int) $category->getId();
                            if ($level > 0) {
                                $addCategories[] = (int) $category->getParentId();
                            }
                            $parentIds[] = $category->getId();
                        }
                    }
                }
                if (!empty($addCategories)) {
                    $categories = array_merge($categories, $addCategories);
                }
            }
        }

        return !empty($categories) ? $categories : $categoryNames;
    }

    /**
     * @param string $attributeCode
     * @param string $label
     * @return mixed
     */
    public function getOptionKeyByLabel($attributeCode, $label)
    {
        return $label;
    }

    protected function _getCatagoriesSeparator()
    {
        return Mage::getStoreConfig(self::CATEGORIES_SEPARATOR_PATH_XML);
    }

    /**
     * @param Mage_Catalog_Model_Product $mainProduct
     * @param array $simpleProductIds
     * @param array $priceChanges
     * @return Bubble_Api_Helper_Catalog_Product
     */
    protected function _initConfigurableAttributesData(Mage_Catalog_Model_Product $mainProduct, $simpleProductIds, $priceChanges = array(), $configurableAttributes = array())
    {
        if (!$mainProduct->isConfigurable() || empty($simpleProductIds)) {
            return $this;
        }

        $decoded = array();
        foreach ($priceChanges as $item) {
        	$attributeCode = $item->key;
        	$optionText = $item->value->key;
        	$priceChange = $item->value->value;
        	if (!isset($decoded[$attributeCode])) {
        		$decoded[$attributeCode] = array();
        	}
        	$decoded[$attributeCode][$optionText] = $priceChange;
        }
        $priceChanges = $decoded;

        $mainProduct->setConfigurableProductsData(array_flip($simpleProductIds));
        $productType = $mainProduct->getTypeInstance(true);
        $productType->setProduct($mainProduct);
        $attributesData = $productType->getConfigurableAttributesAsArray();

        if (empty($attributesData)) {
            // Auto generation if configurable product has no attribute
            $attributeIds = array();
            foreach ($productType->getSetAttributes() as $attribute) {
                if ($productType->canUseAttribute($attribute)) {
                    $attributeIds[] = $attribute->getAttributeId();
                }
            }
            $productType->setUsedProductAttributeIds($attributeIds);
            $attributesData = $productType->getConfigurableAttributesAsArray();
        }
        if (!empty($configurableAttributes) && is_array($configurableAttributes)){
            foreach ($attributesData as $idx => $val) {
                if (!in_array($val['attribute_id'], $configurableAttributes)) {
                    unset($attributesData[$idx]);
                }
            }
        }

	    $products = Mage::getModel('catalog/product')->getCollection()
            ->addIdFilter($simpleProductIds);

        if (count($products)) {
            foreach ($attributesData as &$attribute) {
                $attribute['label'] = $attribute['frontend_label'];
                $attributeCode = $attribute['attribute_code'];
                foreach ($products as $product) {
                    $product->load($product->getId());
                    $optionId = $product->getData($attributeCode);
                    $isPercent = 0;
                    $priceChange = 0;
                    if (!empty($priceChanges) && isset($priceChanges[$attributeCode])) {
                        $optionText = $product->getResource()
                            ->getAttribute($attribute['attribute_code'])
                            ->getSource()
                            ->getOptionText($optionId);
                        if (isset($priceChanges[$attributeCode][$optionText])) {
                            if (false !== strpos($priceChanges[$attributeCode][$optionText], '%')) {
                                $isPercent = 1;
                            }
                            $priceChange = preg_replace('/[^0-9\.,-]/', '', $priceChanges[$attributeCode][$optionText]);
                            $priceChange = (float) str_replace(',', '.', $priceChange);
                        }
                    }
                    $attribute['values'][$optionId] = array(
                        'value_index' => $optionId,
                        'is_percent' => $isPercent,
                        'pricing_value' => $priceChange,
                    );
                }
            }
            $mainProduct->setConfigurableAttributesData($attributesData);
        }

        return $this;
    }

    public function addImages(Mage_Catalog_Model_Product $product, $images)
    {
        $galleryBackendModel = $this->_getGalleryAttribute($product)->getBackend();

        $this->_removeAllImages($product);

        if (!empty($images)) {
            foreach($images as $data) {
                if (is_object($data)) {
                    $data = get_object_vars($data);
                }
                if (isset($data['filename']) && $data['filename']) {
                    $fileName  = $data['filename'];
                } else {
                    throw new Mage_Api_Exception('data_invalid', Mage::helper('catalog')->__('Missing file name.'));
                }
                // Adding image to gallery
                $file = $galleryBackendModel->addImage(
                    $product,
                    $fileName,
                    null,
                    false
                );
                $galleryBackendModel->updateImage($product, $file, $data);

                if (isset($data['types'])) {
                    $galleryBackendModel->setMediaAttribute($product, $data['types'], $file);
                }
            }
        }

        return $this;
    }

    public function setStoreVisibility(Mage_Catalog_Model_Product $product, $storeVisibility)
    {
        $oldStoreId = $product->getStoreId();
        foreach($storeVisibility as $data) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            $product->setStoreId($data['store_id'])->setVisibility($data['visibility']);
            $product->getResource()->saveAttribute($product, 'visibility');
        }
        $product->setStoreId($oldStoreId);
    }

    /**
     * Retrieve gallery attribute from product
     *
     * @param Mage_Catalog_Model_Product $product
     * @param Mage_Catalog_Model_Resource_Eav_Mysql4_Attribute|boolean
     */
    protected function _getGalleryAttribute($product)
    {
        $attributes = $product->getTypeInstance(true)
            ->getSetAttributes($product);

        if (!isset($attributes[Mage_Catalog_Model_Product_Attribute_Media_Api::ATTRIBUTE_CODE])) {
            throw new Mage_Api_Exception('not_media');
        }

        return $attributes[Mage_Catalog_Model_Product_Attribute_Media_Api::ATTRIBUTE_CODE];
    }

    protected function _removeAllImages($product)
    {
        $mediaGalleryData = $product->getData(Mage_Catalog_Model_Product_Attribute_Media_Api::ATTRIBUTE_CODE);

        if (!isset($mediaGalleryData['images']) || !is_array($mediaGalleryData['images'])) {
            return $this;
        }

        $galleryBackendModel = $this->_getGalleryAttribute($product)->getBackend();

        foreach ($mediaGalleryData['images'] as &$image) {
            $image['removed'] = 1;
        }
        $product->setData(Mage_Catalog_Model_Product_Attribute_Media_Api::ATTRIBUTE_CODE, $mediaGalleryData);
    }
}
