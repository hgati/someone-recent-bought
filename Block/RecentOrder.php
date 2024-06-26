<?php

namespace Hgati\SomeoneRecentlyBought\Block;

class RecentOrder extends \Magento\Catalog\Block\Product\AbstractProduct
{

    /**
     * @var \Magento\Framework\Url\Helper\Data
     */
    protected $urlHelper;

    /**
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_objectManager;

    /**
     * Catalog product visibility
     *
     * @var \Magento\Catalog\Model\Product\Visibility
     */
    protected $_catalogProductVisibility;

    /**
     * @var _stockconfig
     */
    protected $_stockConfig;

     /**
     * @var \Magento\CatalogInventory\Helper\Stock
     */
    protected $_stockFilter;

    /**
     * Product collection factory
     *
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    protected $_productCollectionFactory;
    
    protected $_limit; // Limit Product
    protected $_orderInfo; // Limit Product

    protected $_helper;

    /**
     * @param Context $context
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory
     * @param \Magento\Catalog\Model\Product\Visibility $catalogProductVisibility
     * @param array $data
     */
    public function __construct(
        \Magento\Catalog\Block\Product\Context $context,
        \Magento\Framework\Url\Helper\Data $urlHelper,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Catalog\Model\Product\Visibility $catalogProductVisibility,
        \Magento\CatalogInventory\Helper\Stock $stockFilter,
        \Magento\CatalogInventory\Model\Configuration $stockConfig,
        \Hgati\SomeoneRecentlyBought\Helper\Data $helper,
        array $data = []
    ) {
        $this->urlHelper = $urlHelper;
        $this->_objectManager = $objectManager;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_catalogProductVisibility = $catalogProductVisibility;
        $this->_stockFilter = $stockFilter;
        $this->_stockConfig = $stockConfig;
        $this->_helper      = $helper;
        parent::__construct( $context, $data );
    }

    public function getTypeFilter()
    {
        return 'RecentOrder';
    }

    public function getConfig($cfg=null)
    {
        return $this->_helper->getConfigModule($cfg);
    }

    public function getFrontendCfg()
    {
        return $this->_helper->getConfigNotifySlider();
    }

    public function getLoadedProductCollection()
    {
        $this->_limit = (int) $this->getConfig('limit');
        $type = $this->getTypeFilter();
        $fn = 'get' . ucfirst($type);
        $collection = $this->{$fn}();
        /*
        if ($this->_stockConfig->isShowOutOfStock() != 1) {
            $this->_stockFilter->addInStockFilterToCollection($collection);
        }
        */
        $collection->setPageSize($this->_limit)->setCurPage(1);

        $this->_eventManager->dispatch(
            'catalog_block_product_list_collection',
            ['collection' => $collection]
        );

        return $collection;
    }

    public function getFakePurchased()
    {
        $producIds = array();
        $fakeIds = $this->getConfig('general/product_ids');
        if($fakeIds){
            $producIds   = explode(',', $fakeIds); shuffle($producIds);
            $faketime    = explode(',', $this->getConfig('general/faketime'));
            $fakeaddress = explode(',', $this->getConfig('general/fakeaddress'));
            foreach ( $producIds as $key => $id ) {
                $info = array();
                //$info['time'] = isset($faketime[$key]) ? $faketime[$key]: $faketime[array_rand($faketime)];
                $info['time'] = $faketime[array_rand($faketime)];
                //$address = isset($fakeaddress[$key]) ? $fakeaddress[$key]: $fakeaddress[array_rand($fakeaddress)];
                $address = $fakeaddress[array_rand($fakeaddress)];
                $info['address'] = __('from %1', $address);
                $this->_orderInfo[$id] = $info;
            }
        }

        $collection = $this->_productCollectionFactory->create();
        $collection->setVisibility($this->_catalogProductVisibility->getVisibleInCatalogIds());

        $collection = $this->_addProductAttributesAndPrices(
            $collection
        )->addStoreFilter()->addAttributeToFilter('entity_id', array('in' => $producIds));

        return $collection;      
    }

    public function getPurchased()
    {
        $producIds = array();
        $ordercollection = $this->_objectManager->get('Magento\Sales\Model\Order')
                                                ->getCollection()
                                                ->addFieldToSelect(array('*'))
                                                ->setOrder('entity_id','DESC')
                                                ->setPageSize($this->_limit*5)
                                                ->setCurPage(1);

        $i = 0;
        foreach ($ordercollection as $orderDatamodel) {
            $orderId   =   $orderDatamodel->getId();
            $shippingAddress  = $orderDatamodel->getShippingAddress();
            $info       = array();
            if($shippingAddress){
                $city       = $shippingAddress->getCity();
                $country    = $shippingAddress->getData('country_id');
                $info['address'] = __('from %1, %2', $city, $country);
            }
            $order = $this->_objectManager->create('\Magento\Sales\Model\Order')->load($orderId);
            $orderItems = $order->getAllVisibleItems();
            foreach ($orderItems as $item) {
                $productId = $item->getProductId();
                if(!in_array($productId, $producIds)){
                    $id = $item->getProductId();
                    $producIds[]    =   $id;
                    $info['time']   = $item->getData('created_at');
                    $this->_orderInfo[$id] = $info;
                    $i ++;
                    if($i >= $this->_limit) break;
                } 

            }
            if($i >= $this->_limit) break;
        }

        $collection = $this->_productCollectionFactory->create();
        $collection->setVisibility($this->_catalogProductVisibility->getVisibleInCatalogIds());

        $collection = $this->_addProductAttributesAndPrices(
            $collection
        )->addStoreFilter()->addAttributeToFilter('entity_id', array('in' => $producIds));

        return $collection;       
    }

    public function getRecentOrder(){

        if($this->getConfig('general/fakeinfo')){
            return $this->getFakePurchased();
        }
        
        return $this->getPurchased();
    }

    public function getInfoPurchased(\Magento\Catalog\Model\Product $product)
    {
        $productId = $product->getId();
        $info = array();
        if( isset( $this->_orderInfo[ $productId ] ) ){
            $info = $this->_orderInfo[ $productId ];
        }
        return $info;
    }

    public function getInfoTime($time)
    {
        if($this->getConfig('general/fakeinfo')){
            return $time;
        }

        return $this->time_elapsed_string($time);
    }

    public function time_elapsed_string($datetime, $full = false) {
        $now = new \DateTime;
        $ago = new \DateTime($datetime);
        $diff = $now->diff($ago);
        $ary = [];
        $ary['d'] = $diff->d;

        $ary['w'] = floor($ary['d'] / 7);
        $ary['d'] -= $ary['w'] * 7;

        $string = array(
            'y' => __('year'),
            'm' => __('month'),
            'w' => __('week'),
            'd' => __('day'),
            'h' => __('hour'),
            'i' => __('minute'),
            's' => __('second'),
        );
        foreach ($string as $k => &$v) {
            if (isset($ary[$k])) {
                $v = $ary[$k] . ' ' . $v . ($ary[$k] > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ' . __('ago') : __('just now');
    }

    /**
     * Get post parameters
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    public function getAddToCartPostParams(\Magento\Catalog\Model\Product $product)
    {
        $url = $this->getAddToCartUrl($product);
        return [
            'action' => $url,
            'data' => [
                'product' => $product->getEntityId(),
                \Magento\Framework\App\ActionInterface::PARAM_NAME_URL_ENCODED =>
                    $this->urlHelper->getEncodedUrl($url),
            ]
        ];
    }

}
