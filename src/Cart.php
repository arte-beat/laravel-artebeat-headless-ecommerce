<?php

namespace Webkul\GraphQLAPI;

use Webkul\Checkout\Cart as BaseCart;
use Webkul\Checkout\Repositories\CartAddressRepository;
use Webkul\Checkout\Repositories\CartItemRepository;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Customer\Repositories\CustomerAddressRepository;
use Webkul\Customer\Repositories\WishlistRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Tax\Repositories\TaxCategoryRepository;
use Webkul\Core\Repositories\CommissionRateRepository;

class Cart extends BaseCart
{
    /**
     * Create a new class instance.
     *
     * @param  \Webkul\Checkout\Repositories\CartRepository             $cartRepository
     * @param  \Webkul\Checkout\Repositories\CartItemRepository         $cartItemRepository
     * @param  \Webkul\Checkout\Repositories\CartAddressRepository      $cartAddressRepository
     * @param  \Webkul\Product\Repositories\ProductRepository           $productRepository
     * @param  \Webkul\Tax\Repositories\TaxCategoryRepository           $taxCategoryRepository
     * @param  \Webkul\Customer\Repositories\WishlistRepository         $wishlistRepository
     * @param  \Webkul\Customer\Repositories\CustomerAddressRepository  $customerAddressRepository
     * @param  \Webkul\Core\Repositories\CommissionRateRepository  $commissionRateRepository
     * @return void
     */
    public function __construct(
        protected CartRepository $cartRepository,
        protected CartItemRepository $cartItemRepository,
        protected CartAddressRepository $cartAddressRepository,
        protected ProductRepository $productRepository,
        protected TaxCategoryRepository $taxCategoryRepository,
        protected WishlistRepository $wishlistRepository,
        protected CustomerAddressRepository $customerAddressRepository,
        protected CommissionRateRepository $commissionRateRepository
    ) {
        parent::__construct(
            $cartRepository,
            $cartItemRepository,
            $cartAddressRepository,
            $productRepository,
            $taxCategoryRepository,
            $wishlistRepository,
            $customerAddressRepository,
            $commissionRateRepository
        );
    }


    /**
     * Return current logged in customer
     *
     * @return \Webkul\Customer\Contracts\Customer|bool
     */
    public function getCurrentCustomer()
    {
        $token = 0;

        if (request()->hasHeader('authorization')) {
            $headerValue = explode('Bearer ', request()->header('authorization'));

            if (isset($headerValue[1]) && $headerValue[1]) {
                $token = $headerValue[1];
            }
        }

        $guard = ($token || request()->has('token')) ? 'api' : 'customer';

        return auth()->guard($guard);
    }
}
