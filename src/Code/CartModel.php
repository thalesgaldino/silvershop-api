<?php

namespace Toast\ShopAPI\Model;

/**
 * Class CartModel
 */
class CartModel extends ShopModelBase
{
    protected $id;
    protected $hash;
    protected $quantity;
    protected $total_price;
    protected $total_price_nice;
    protected $subtotal_price;
    protected $subtotal_price_nice;
    protected $cart_link;
    protected $checkout_link;
    protected $items = [];
    protected $modifiers = [];

    protected static $fields = [
        'code',
        'message',
        'id',
        'hash',
        'currency',
        'currency_symbol',
        'quantity',
        'total_price',
        'total_price_nice',
        'subtotal_price',
        'subtotal_price_nice',
        'cart_link',
        'checkout_link',
        'continue_link',
        'items',
        'modifiers',
        'shipping_id',
    ];

    public function __construct()
    {
        parent::__construct();

        $date       = date_create();
        $this->hash = hash('sha256', $date->format('U'));

        if ($this->order) {

            $this->extend('updateCartOrder', $this->order);

            $this->hash                = hash('sha256', ShoppingCart::curr()->LastEdited . $this->order->ID);
            $this->id                  = $this->order->ID;
            $this->quantity            = $this->order->Items()->Quantity();
            $this->subtotal_price      = number_format($this->order->SubTotal(), 2);
            $this->subtotal_price_nice = sprintf('%s%.2f', Config::inst()->get('Currency', 'currency_symbol'), $this->order->SubTotal());
            $this->total_price         = number_format($this->order->Total(), 2);
            $this->total_price_nice    = sprintf('%s%.2f', Config::inst()->get('Currency', 'currency_symbol'), $this->order->Total());

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
            $this->quantity            = 0;
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

        if ($buyableID && is_numeric($buyableID)) {

            // Implement the same logic as on the AddProductForm and the VariationForm
            $product = DataObject::get_by_id('Product', $buyableID);

            if ($product && $product->exists()) {
                $quantity = $quantity > 0 ? $quantity : 1;

                $this->extend('onBeforeAddItem', $product);

                try {
                    $result = $this->cart->add($product, $quantity);
                } catch (Exception $e) {
                    $this->code         = 'error';
                    $this->message      = $e->getMessage();
                    $this->cart_updated = false;

                    return $this->getActionResponse();
                }

                if ($result === true || $result instanceof OrderItem) {
                    $this->code    = 'success';
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
                    $this->code         = 'error';
                    $this->message      = $this->cart->getMessage();
                    $this->cart_updated = false;
                }
            } else {
                $this->code         = 'error';
                $this->message      = _t('SHOP_API_MESSAGES.ProductNotFound', 'Product does not exist');
                $this->cart_updated = false;
            }
        } else {
            $this->code         = 'error';
            $this->message      = _t('SHOP_API_MESSAGES.IncorrectIDParam', 'Missing or malformed ID');
            $this->cart_updated = false;
        }

        $this->extend('onAfterAddItem');

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

        if ($buyableID && is_numeric($buyableID)) {

            $productModel = ProductModel::create($buyableID);

            if ($productAttributes && is_array($productAttributes)) {

                $this->extend('onBeforeAddVariation', $productAttributes);

                if ($productVariation = $productModel->getVariationByAttributes($productAttributes)) {
                    $quantity = $quantity > 0 ? $quantity : 1;
                    try {
                        $result = $this->cart->add($productVariation, $quantity);
                    } catch (Exception $e) {
                        $this->code         = 'error';
                        $this->message      = $e->getMessage();
                        $this->cart_updated = false;

                        return $this->getActionResponse();
                    }

                    if ($result === true || $result instanceof OrderItem) {
                        $this->code    = 'success';
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
                        $this->code         = 'error';
                        $this->message      = $this->cart->getMessage();
                        $this->cart_updated = false;
                    }
                } else {
                    $this->code         = 'error';
                    $this->message      = _t('SHOP_API_MESSAGES.VariationNotAvailable', 'That variation is not available');
                    $this->cart_updated = false;
                }
            } else {
                $this->code         = 'error';
                $this->message      = _t('SHOP_API_MESSAGES.IncorrectProductAttributesFormat', 'Missing [ProductAttributes] GET variable in correct format');
                $this->cart_updated = false;
            }

        } else {
            $this->code         = 'error';
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

        $this->extend('onBeforeApplyCoupon');

        if ($coupon = OrderCoupon::get_by_code($code)) {

            $this->extend('updateCoupon', $coupon);

            if (!$coupon->validateOrder($this->order, ["CouponCode" => $code])) {
                $this->code         = 'error';
                $this->message      = _t('SHOP_API_MESSAGES.CouponInvalid', 'Could not apply coupon.');
                $this->cart_updated = false;
            } else {
                Session::set("cart.couponcode", strtoupper($code));

                $this->order->getModifier("OrderDiscountModifier", true);

                $this->code    = 'success';
                $this->message = _t('SHOP_API_MESSAGES.CouponApplied', 'Coupon applied.');
                // Set the cart updated flag, and which components to refresh
                $this->cart_updated = true;
                $this->refresh      = [
                    'cart',
                    'summary'
                ];
            }
        } else {
            $this->code         = 'error';
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
        $this->extend('onBeforeClearCart');

        if ($this->order->Items()->exists()) {
            $this->order->Items()->removeAll();

            $this->code    = 'success';
            $this->message = _t('SHOP_API_MESSAGES.CartCleared', 'Cart cleared');
            // Set the cart updated flag, and which components to refresh
            $this->cart_updated = true;
            $this->refresh      = [
                'cart',
                'summary'
            ];
        } else {
            $this->code         = 'error';
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
        $this->extend('onBeforeUpdateShipping', $zoneID);

        // find the shiping option with the zone $zoneID added to it
        $shippingID = ZonedShippingRate::get()->exclude('ZonedShippingMethodID', 0)->filter(['ZoneId' => $zoneID])->first()->ZonedShippingMethodID;
        // search shipping methods that containe

        $this->order->setShippingMethod(ShippingMethod::get()->byID($shippingID));
        $this->code    = 'success';
        $this->message = _t('SHOP_API_MESSAGES.ShippingUpdated', 'Cart shipping updated');
        // Set the cart updated flag, and which components to refresh
        $this->cart_updated = true;
        $this->shipping_id = $shippingID;
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
}