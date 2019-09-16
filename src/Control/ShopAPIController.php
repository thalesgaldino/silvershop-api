<?php

namespace Toast\ShopAPI\Control;

use SilverShop\Cart\ShoppingCart;
use SilverShop\Page\Product;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use Toast\ShopAPI\Model\CartItemModel;
use Toast\ShopAPI\Model\CartModel;
use Toast\ShopAPI\Model\ComponentModel;
use Toast\ShopAPI\Model\ProductModel;
use Toast\ShopAPI\Model\VariationModel;

/**
 * Class ShopAPIController
 */
class ShopAPIController extends Controller
{
    /**
     * @var CartModel $cart
     */
    protected $cart;

    private static $url_handlers = [
        'cart//$Action/$ID/$OtherAction' => 'handleAction'
    ];

    private static $allowed_actions = [
        'cart',
        'item',
        'product',
        'add',
        'submitorder',
        'clear',
        'component',
        'promocode',
        'ping',
        'shipping',
        'variation'
    ];

//    public function __construct()
//    {
//        parent::__construct();
//
//        $this->cart = CartModel::create();
//    }

    public function init()
    {
        parent::init();

        $this->cart = CartModel::create();
    }

    /* -----------------------------------------
     * Handlers
     * ----------------------------------------*/

    public function index(HTTPRequest $request)
    {
        return $this->processResponse($this->cart->get());
    }

    /**
     * @param HTTPRequest $request
     * @return string
     */
    public function promocode(HTTPRequest $request)
    {
        /** =========================================
         * @var OrderCoupon $coupon
         * ========================================*/

        if (Product::has_extension('ProductDiscountExtension')) {
            $code = $request->getVar('code');

            if ($code) {
                return $this->processResponse($this->cart->applyCoupon($code));
            }
        }

        // TODO: Add error response for module not installed

        return $this->processResponse($this->cart->get());
    }

    /**
     * Controls Order Items (quantities)
     *
     * @param HTTPRequest $request
     * @return string
     */
    public function item(HTTPRequest $request)
    {
        $id = $request->param('ID');

        if ($id && is_numeric($id)) {
            $item = CartItemModel::create($id);

            // TODO: Validation on quantity

            // process action
            switch ($request->param('OtherAction')) {
                case 'setQuantity':
                    return $this->processResponse($item->setQuantity($request->getVar('quantity')));
                case 'removeOne':
                    return $this->processResponse($item->addOrRemoveItems(false));
                case 'removeAll':
                    return $this->processResponse($item->setQuantity(0));
                case 'addOne':
                    return $this->processResponse($item->addOrRemoveItems(true));
                case 'removeQuantity':
                    return $this->processResponse($item->addOrRemoveItems(false, $request->getVar('quantity')));
                case 'addQuantity':
                    return $this->processResponse($item->addOrRemoveItems(true, $request->getVar('quantity')));
                default:
                    return $this->processResponse($this->cart->get());
            }
        }

        return $this->processResponse();
    }


    public function shipping(HTTPRequest $request)
    {
        $cart = $this->cart;
        // process action
        switch ($request->param('OtherAction')) {
            case 'update':
                return $this->processResponse($cart->updateShipping($request->getVar('ID')));
            case 'get':
                return $this->processResponse($cart->getShipping());
            default:
                return $this->processResponse($cart->getShipping());
        }

        return $this->processResponse();
    }


    /**
     * @param HTTPRequest $request
     * @return string
     *
     * Checkout component model
     */
    public function component(HTTPRequest $request)
    {
        $type = $request->param('ID');

        if ($type) {
            $component = ComponentModel::create($type);

            return $this->processResponse($component->get());
        }

        return $this->processResponse($this->cart->get());
    }

    /**
     * Controls Product functions (get, add to cart)
     *
     * @param HTTPRequest $request
     * @return string
     */
    public function product(HTTPRequest $request)
    {
        $id = $request->param('ID');

        if ($id && is_numeric($id)) {
            $product = ProductModel::create($id);

            $cart = $this->cart;

            // process action
            switch ($request->param('OtherAction')) {
                case 'add':
                    return $this->processResponse($cart->addItem($id, $request->getVar('quantity')));
                case 'addVariation':
                    return $this->processResponse($cart->addVariation($id, $request->getVar('quantity'), $request->getVar('ProductAttributes')));
                default:
                    return $this->processResponse($product->get());
            }
        }

        return $this->processResponse();
    }

    public function add(HTTPRequest $request)
    {
        $bodyArray = json_decode($request->getBody(), true);
        
        if ($bodyArray && sizeof($bodyArray) != 0) {
            $cart = $this->cart;
            $data = $cart->addItems($bodyArray);
            return $this->processResponse($data);
        }

        return $this->processResponse();
    }

    public function submitorder(HTTPRequest $request)
    {
        $bodyArray = json_decode($request->getBody(), true);
        
        //option for send order with cart
        if (isset($bodyArray['datacart'])){
            $cart = $this->cart;
            $cart->addItems($bodyArray['datacart']);
        }

        if ($bodyArray) {
            $cart = $this->cart;
            $data = $cart->sendOrder($bodyArray);
            return $this->processResponse($data);
        }

        return $this->processResponse();
    }

    /**
     * Controls Varr functions (get, add to cart)
     *
     * @param HTTPRequest $request
     * @return string
     */
    public function variation(HTTPRequest $request)
    {
        $id = $request->param('ID');

        if ($id && is_numeric($id)) {
            $variation = VariationModel::create($id);

            $cart = $this->cart;

            // process action
            switch ($request->param('OtherAction')) {
                default:
                    return $this->processResponse($variation->get());
            }
        }
        return $this->processResponse();
    }

    public function clear(HTTPRequest $request)
    {
        return $this->processResponse($this->cart->clear());
    }

    public function ping(HTTPRequest $request)
    {
        $hash = $this->cart->getHash() != $request->getVar('hash') ? $this->cart->getHash() : $request->getVar('hash');

        return $this->processResponse(['hash' => $hash]);
    }

    /* -----------------------------------------
     * Helpers
     * ----------------------------------------*/

    public function processResponse($data = [])
    {
        if ($this->request->param('Action') != 'ping' && !empty($this->request->param('Action'))) {
            if ($cart = ShoppingCart::curr()) {
                $cart->setField('Hash', $this->cart->getHash());
                $cart->write();
            }
        }

        $this->extend('updateResponseData', $data);

        return json_encode($data, JSON_HEX_QUOT | JSON_HEX_TAG);
    }
}
