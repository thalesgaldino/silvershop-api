<?php

namespace Toast\ShopAPI\Model;

use Exception;
use Omnipay\Common\Currency;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\OrderItem;
use SilverShop\ORM\FieldType\ShopCurrency;
use SilverShop\Model\Order;
use SilverShop\Page\Product;
use SilverShop\Checkout\Checkout;
use SilverShop\Checkout\OrderProcessor;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverShop\Model\Variation\Variation;
use SilverStripe\Omnipay\GatewayFieldsFactory;
use SilverStripe\Omnipay\GatewayInfo;

/**
 * Class CartModel
 */
class CartModel extends ShopModelBase
{
    protected $id;
    protected $hash;
    protected $total_items;
    protected $total_price;
    protected $total_price_nice;
    protected $subtotal_price;
    protected $subtotal_price_nice;
    protected $cart_link;
    protected $checkout_link;
    protected $items = [];
    protected $wish_list_link;
    protected $wish_list_items = [];
    protected $total_wish_list_items;
    protected $compare_list_link;
    protected $compare_list_items = [];
    protected $total_compare_list_items;
    protected $enquiry_list_link;
    protected $enquiry_list_items = [];
    protected $total_enquiry_list_items;
    protected $modifiers = [];

    protected static $fields = [
        'id',
        'currency',
        'currency_symbol',
        'total_items',
        'total_price',
        'total_price_nice',
        'subtotal_price',
        'subtotal_price_nice',
        'cart_link',
        'checkout_link',
        'items',
        'wish_list_link',
        'wish_list_items',
        'total_wish_list_items',
        'compare_list_link',
        'compare_list_items',
        'total_compare_list_items',
        'enquiry_list_link',
        'enquiry_list_items',
        'total_enquiry_list_items',
        'modifiers',
        'shipping_id',
    ];

    public function __construct()
    {
        parent::__construct();

        $date       = date_create();
        $this->hash = hash('sha256', $date->format('U'));

        if ($this->getWishList()) {
            foreach ($this->getWishList() as $item) {
                $this->wish_list_items[] = WishListItemModel::create($item)->get();
            }
            $this->total_wish_list_items = count($this->getWishList());
        }else{
            $this->wish_list_items = [];
            $this->total_wish_list_items = Null;
        }


        if ($this->getCompareList()) {
            foreach ($this->getCompareList() as $item) {
                $this->compare_list_items[] = CompareListItemModel::create($item)->get();
            }
            $this->total_compare_list_items = count($this->getCompareList());
        }else{
            $this->compare_list_items = [];
            $this->total_compare_list_items = Null;
        }


        if ($this->getEnquiryList()) {

            foreach ($this->getEnquiryList() as $item) {
                $this->enquiry_list_items[] = EnquiryListItemModel::create($item)->get();
            }
            $this->total_enquiry_list_items = count($this->getEnquiryList());
        }else{
            $this->enquiry_list_items = [];
            $this->total_enquiry_list_items = Null;
        }


        if ($this->order && $this->order->Items()->Count()) {

            $this->extend('updateCartOrder', $this->order);

            $this->hash                = hash('sha256', ShoppingCart::curr()->LastEdited . $this->order->ID);
            $this->id                  = $this->order->ID;
            $this->total_items         = $this->order->Items()->Quantity();
            $this->subtotal_price      = number_format($this->order->SubTotal(), 2);
            $this->subtotal_price_nice = sprintf('%s%.2f', Config::inst()->get(ShopCurrency::class, 'currency_symbol'), $this->order->SubTotal());
            $this->total_price         = number_format($this->order->Total(), 2);
            $this->total_price_nice    = sprintf('%s%.2f', Config::inst()->get(ShopCurrency::class, 'currency_symbol'), $this->order->Total());

            // Add items
            if ($this->order->Items()) {
                foreach ($this->order->Items() as $item) {
                    $this->items[] = CartItemModel::create($item->ID)->get();
                }
            }

            // Add modifiers
            if ($this->order->Modifiers()) {
                foreach ($this->order->Modifiers() as $modifier) {
                    if ($modifier->ShowInTable()) {
                        $this->modifiers[] = ModifierModel::create($modifier->ID)->get();
                    }
                }
            }
        } else {
            $this->total_items         = 0;
            $this->total_price         = 0;
            $this->total_price_nice    = 0;
            $this->subtotal_price      = 0;
            $this->subtotal_price_nice = 0;
        }

        $this->extend('onAfterSetup');
    }

    /**
     * Add a plain item (no variations)
     *
     * @param     $buyableID
     * @param int $quantity
     * @return array
     */
    public function addItem($buyableID, $quantity)
    {
        /** =========================================
         * @var Product $product
         * ========================================*/

        $this->called_method = 'addItem';

        if ($buyableID && is_numeric($buyableID)) {

            // Implement the same logic as on the AddProductForm and the VariationForm
            $product = DataObject::get_by_id(Product::class, $buyableID);

            if ($product && $product->exists()) {
                $quantity = $quantity > 0 ? $quantity : 1;

                $this->extend('onBeforeAddItem', $product);

                if ($this->cart) {
                    try {
                        $result = $this->cart->add($product, $quantity);
                    } catch (Exception $e) {
                        $this->status       = 'error';
                        $this->code         = 400;
                        $this->message      = $e->getMessage();
                        $this->cart_updated = false;

                        return $this->getActionResponse();
                    }

                    if ($result === true || $result instanceof OrderItem) {
                        $this->status  = 'success';
                        $this->message = _t(
                            'SHOP_API_MESSAGES.ItemAdded',
                            'Item{plural} added successfully.',
                            ['plural' => $quantity == 1 ? '' : 's']
                        );
                        // Set the cart updated flag, and which components to refresh
                        $this->cart_updated = true;
                        $this->refresh      = [
                            'cart',
                            'summary',
                            'shippingmethod'
                        ];
                        // Set new total items
                        $this->total_items = $result instanceof OrderItem ? $result->Order()->Items()->Quantity() : $quantity;

                    } else {
                        $this->code         = 400;
                        $this->status       = 'error';
                        $this->message      = $this->cart->getMessage();
                        $this->cart_updated = false;
                    }
                } else {
                    $this->code         = 404;
                    $this->status       = 'error';
                    $this->message      = _t('SHOP_API_MESSAGES.CartNotFound', 'Cart not found');
                    $this->cart_updated = false;
                }
            } else {
                $this->code         = 404;
                $this->status       = 'error';
                $this->message      = _t('SHOP_API_MESSAGES.ProductNotFound', 'Product does not exist');
                $this->cart_updated = false;
            }
        } else {
            $this->code         = 400;
            $this->status       = 'error';
            $this->message      = _t('SHOP_API_MESSAGES.IncorrectIDParam', 'Missing or malformed ID');
            $this->cart_updated = false;
        }

        $this->extend('onAfterAddItem');

        return $this->getActionResponse();
    }

    public function addItems($buyableArray)
    {
        $this->called_method = 'addItems';

        if ($this->cart) {
            try {
                for ($i = 0; $i < sizeof($buyableArray); $i++) {
                    $id = $buyableArray[$i]['id'];
                    $quantity = $buyableArray[$i]['quantity'];
                    $product = DataObject::get_by_id(Product::class, $id);
                    //FIXME: result should be combined with all the products
                    $result = $this->cart->add($product, $quantity);
                }
            } catch (Exception $e) {
                $this->status       = 'error';
                $this->code         = 400;
                $this->message      = $e->getMessage();
                $this->cart_updated = false;

                return $this->getActionResponse();
            }

            if ($result === true || $result instanceof OrderItem) {
                $this->status  = 'success';
                $this->message = 'many items!!!';
                //FIXME: get translation sorted
                // $this->message = _t(
                //     'SHOP_API_MESSAGES.ItemAdded',
                //     'Item{plural} added successfully.',
                //     ['plural' => $quantity0 == 1 ? '' : 's']
                // );
                // Set the cart updated flag, and which components to refresh
                $this->cart_updated = true;
                $this->refresh      = [
                    'cart',
                    'summary',
                    'shippingmethod'
                ];
                // Set new total items
                $this->total_items = $result instanceof OrderItem ? $result->Order()->Items()->Quantity() : $quantity0;

            } else {
                $this->code         = 400;
                $this->status       = 'error';
                $this->message      = $this->cart->getMessage();
                $this->cart_updated = false;
            }
        }
        return $this->getActionResponse();
    }

    public function sendOrder($bodyArray){
        $this->called_method = 'sendOrder';

        $order = null;
        if ($this->cart){
            $order = $this->cart->current();
        }
        
        if ($order) {
            $gateway = Checkout::get($order)->getSelectedPaymentMethod(false);
            if (GatewayInfo::isOffsite($gateway)
                || GatewayInfo::isManual($gateway)
                || $this->config->hasComponentWithPaymentData()
            ) {
                return $this->submitpayment($bodyArray);
            }else{
                $this->code         =  500;
                $this->status       = 'error';
                $this->message      = 'Payment not available at the moment. Please try again later';
                $this->cart_updated = false;
            }
        } else {
            $this->code         = 404;
            $this->status       = 'error';
            $this->message      = _t('SHOP_API_MESSAGES.ProductNotFound', 'Product does not exist');
            $this->cart_updated = false;
        }
        return $this->getActionResponse();
    }

    public function submitpayment($bodyArray){
        
        $order = $this->cart->current();
        if ($bodyArray) {
            //$bodyArray in the order for guest ordering
            $order->setField('FirstName', $bodyArray['firstname']);
            $order->setField('Surname', $bodyArray['surname']);
            $order->setField('Email', $bodyArray['email']);
            $order->setField('Notes', $bodyArray['notes']);
        }

        // final recalculation, before making payment
        $order->calculate();

        $gateway = Checkout::get($order)->getSelectedPaymentMethod(false);

        $orderProcessor = OrderProcessor::create($order);

        // try to place order before payment, if configured
        if (Order::config()->place_before_payment) {
            if (!$orderProcessor->placeOrder()) {
                $this->code         =  500;
                $this->status       = 'error';
                $this->message      = $orderProcessor->getError();
                $this->cart_updated = false;
            }else{
                $this->status  = 'success';
                $this->message = 'order sent!!!';
                // Set the cart updated flag, and which components to refresh
                $this->cart_updated = true;
                $this->refresh      = [
                    'cart',
                    'summary',
                    'shippingmethod'
                ];
                // Set new total items
                $this->total_items = 0;
            }
        }else{
            $this->code         =  500;
            $this->status       = 'error';
            $this->message      = 'Payment not available at the moment. Please try again later';
            $this->cart_updated = false;
        }

        return $this->getActionResponse();
    }

    /**
     * Add a product that has variations
     *
     * @param       $buyableID
     * @param int   $quantity
     * @param array $productAttributes
     * @return array
     */
    public function addVariation($buyableID, $quantity, $productAttributes = [])
    {
        /** =========================================
         * @var Product      $product
         * @var ProductModel $productModel
         * ========================================*/

        $this->called_method = 'addVariation';

        if ($buyableID && is_numeric($buyableID)) {

            $productModel = ProductModel::create($buyableID);

            if ($productAttributes && is_array($productAttributes)) {

                $this->extend('onBeforeAddVariation', $productAttributes);

                if ($productVariation = $productModel->getVariationByAttributes($productAttributes)) {
                    $quantity = $quantity > 0 ? $quantity : 1;
                    try {
                        $result = $this->cart->add($productVariation, $quantity);
                    } catch (Exception $e) {
                        $this->status       = 'error';
                        $this->message      = $e->getMessage();
                        $this->cart_updated = false;

                        return $this->getActionResponse();
                    }

                    if ($result === true || $result instanceof OrderItem) {
                        $this->status  = 'success';
                        $this->message = _t(
                            'SHOP_API_MESSAGES.ItemAdded',
                            'Item{plural} added successfully.',
                            ['plural' => $quantity == 1 ? '' : 's']
                        );
                        // Set the cart updated flag, and which components to refresh
                        $this->cart_updated = true;
                        $this->refresh      = [
                            'cart',
                            'summary',
                            'shippingmethod'
                        ];

                    } else {
                        $this->code         = 400;
                        $this->status       = 'error';
                        $this->message      = $this->cart->getMessage();
                        $this->cart_updated = false;
                    }
                } else {
                    $this->code         = 400;
                    $this->status       = 'error';
                    $this->message      = _t('SHOP_API_MESSAGES.VariationNotAvailable', 'That variation is not available');
                    $this->cart_updated = false;
                }
            } else {
                $this->code         = 400;
                $this->status       = 'error';
                $this->message      = _t('SHOP_API_MESSAGES.IncorrectProductAttributesFormat', 'Missing [ProductAttributes] GET variable in correct format');
                $this->cart_updated = false;
            }

        } else {
            $this->code         = 400;
            $this->status       = 'error';
            $this->message      = _t('SHOP_API_MESSAGES.IncorrectIDParam', 'Missing or malformed ID');
            $this->cart_updated = false;
        }

        $this->extend('onAfterAddVariation');

        return $this->getActionResponse();
    }

    public function applyCoupon($code)
    {
        /** =========================================
         * @var OrderCoupon $coupon
         * ========================================*/

        $this->called_method = 'applyCoupon';

        // TODO: Check if Discounts module is installed

        $this->extend('onBeforeApplyCoupon');

        if ($coupon = OrderCoupon::get_by_code($code)) {

            $this->extend('updateCoupon', $coupon);

            if (!$coupon->validateOrder($this->order, ["CouponCode" => $code])) {
                $this->status       = 'error';
                $this->message      = _t('SHOP_API_MESSAGES.CouponInvalid', 'Could not apply coupon.');
                $this->cart_updated = false;
            } else {
                Controller::curr()->getRequest()->getSession()->set("cart.couponcode", strtoupper($code));

                $this->order->getModifier("OrderDiscountModifier", true);

                $this->status  = 'success';
                $this->message = _t('SHOP_API_MESSAGES.CouponApplied', 'Coupon applied.');
                // Set the cart updated flag, and which components to refresh
                $this->cart_updated = true;
                $this->refresh      = [
                    'cart',
                    'summary'
                ];
            }
        } else {
            $this->status       = 'error';
            $this->code         = 404;
            $this->message      = _t('SHOP_API_MESSAGES.CouponNotFound', 'Coupon could not be found');
            $this->cart_updated = false;
        }

        $this->extend('onAfterApplyCoupon');

        return $this->getActionResponse();
    }

    /**
     * Remove all items from the cart
     *
     * @return array
     */
    public function clear()
    {
        $this->called_method = 'clear';

        $this->extend('onBeforeClearCart');

        if ($this->order->Items()->exists()) {
            $this->order->Items()->removeAll();

            $this->status  = 'success';
            $this->message = _t('SHOP_API_MESSAGES.CartCleared', 'Cart cleared');
            // Set the cart updated flag, and which components to refresh
            $this->cart_updated = true;
            $this->refresh      = [
                'cart',
                'summary'
            ];
        } else {
            $this->status       = 'error';
            $this->code         = 200;
            $this->message      = _t('SHOP_API_MESSAGES.CartAlreadyEmpty', 'Cart already empty');
            $this->cart_updated = false;
        }

        $this->extend('onAfterClearCart');

        return $this->getActionResponse();
    }

    public function getHash()
    {
        return $this->hash;
    }

    public function updateShipping($zoneID)
    {
        $this->called_method = 'updateShipping';

        // TODO: Check if the Shipping module is installed

        $this->extend('onBeforeUpdateShipping', $zoneID);

        // find the shiping option with the zone $zoneID added to it
        $shippingID = ZonedShippingRate::get()->exclude('ZonedShippingMethodID', 0)->filter(['ZoneId' => $zoneID])->first()->ZonedShippingMethodID;
        // search shipping methods that containe

        $this->order->setShippingMethod(ShippingMethod::get()->byID($shippingID));
        $this->status  = 'success';
        $this->message = _t('SHOP_API_MESSAGES.ShippingUpdated', 'Cart shipping updated');
        // Set the cart updated flag, and which components to refresh
        $this->cart_updated = true;
        $this->shipping_id  = $shippingID;
        $this->refresh      = [
            'cart',
            'summary',
            'shipping'
        ];

        $this->extend('onAfterUpdateShipping');

        return $this->getActionResponse();
    }

    public function getShipping()
    {
        $this->called_method = 'getShipping';

//        Debug::dump($this->order->getShippingMethods());
//        die();
        $this->message = _t('SHOP_API_MESSAGES.GetShipping', 'Get current shipping method');
        // Set the cart updated flag, and which components to refresh
        $this->cart_updated = false;
        $this->refresh      = [
            'cart',
            'summary',
            'shipping'
        ];
        return $this->getActionResponse();
    }


    public function getWishList()
    {
//        $this->called_method = 'toggle';
        $request = Injector::inst()->get(HTTPRequest::class);
        $session = $request->getSession();
        $wishList = $session->get('wishList');

        $wishListVariations = $session->get('wishList_variations');
        if ($wishList){
            foreach ($wishList as $item) {
                if (Product::get()->byID($item)) {

                }else{

                    $key = array_search ($item, $wishList);
//                Debug::dump($key);
                    unset($wishList[$key]);
                }
            }
        }
        if ($wishListVariations){
            foreach ($wishListVariations as $item) {
                if (Variation::get()->byID($item)) {
//                    $compareCount++;
                }else{
                    $key = array_search ($item, $wishListVariations);
                    unset($wishListVariations[$key]);
                }
            }
        }
        if ($wishListVariations && $wishList){
            return $result = array_merge($wishList, $wishListVariations);
        }elseif ($wishListVariations){
            return $wishListVariations;
        }elseif ($wishList){
            return $wishList;
        }
    }

    public function getCompareList()
    {
//        $this->called_method = 'toggle';
        $request = Injector::inst()->get(HTTPRequest::class);
        $session = $request->getSession();
        $results = new ArrayList();
        $compareList = $session->get('compareList');
        $compareListVariations = $session->get('compareList_variations');
        if ($compareList){
            foreach ($compareList as $item) {
                if (Product::get()->byID($item)) {

                }else{

                    $key = array_search ($item, $compareList);
//                Debug::dump($key);
                    unset($compareList[$key]);
                }
            }
        }
        if ($compareListVariations){
            foreach ($compareListVariations as $item) {
                if (Variation::get()->byID($item)) {
//                    $compareCount++;
                }else{
                    $key = array_search ($item, $compareListVariations);
                    unset($compareListVariations[$key]);
                }
            }
        }
        if ($compareListVariations && $compareList){
            return $result = array_merge($compareList, $compareListVariations);
        }elseif ($compareListVariations){
            return $compareListVariations;
        }elseif ($compareList){
            return $compareList;
        }

    }

    public function getEnquiryList()
    {
//        $this->called_method = 'toggle';
        $request = Injector::inst()->get(HTTPRequest::class);
        $session = $request->getSession();
        $results = new ArrayList();
        $compareList = $session->get('enquiryList');
        $compareListVariations = $session->get('enquiryList_variations');
        if ($compareList){
            foreach ($compareList as $item) {
                if (Product::get()->byID($item)) {

                }else{

                    $key = array_search ($item, $compareList);
//                Debug::dump($key);
                    unset($compareList[$key]);
                }
            }
        }
        if ($compareListVariations){
            foreach ($compareListVariations as $item) {
                if (Variation::get()->byID($item)) {
//                    $compareCount++;
                }else{
                    $key = array_search ($item, $compareListVariations);
                    unset($compareListVariations[$key]);
                }
            }
        }
        if ($compareListVariations && $compareList){
            return $result = array_merge($compareList, $compareListVariations);
        }elseif ($compareListVariations){
            return $compareListVariations;
        }elseif ($compareList){
            return $compareList;
        }

    }
}
