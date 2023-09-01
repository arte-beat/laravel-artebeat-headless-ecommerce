<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\ThankYouScreen;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\TicketOrder;
use Webkul\Core\Contracts\Validations\Slug;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Product\Repositories\TicketOrderRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Customer\Repositories\CustomerAddressRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ThankYouScreenMutation extends Controller
{
    /**
     * @var int
     */
    protected $id;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Product\Repositories\ProductRepository  $productRepository
     * @param \Webkul\Customer\Repositories\CustomerAddressRepository  $customerAddressRepository
     * @param  \Webkul\Product\Repositories\TicketOrderRepository  $ticketOrderRepository
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected CustomerAddressRepository $customerAddressRepository,
        protected TicketOrderRepository $ticketOrderRepository
    ) {
        $this->guard = 'api';
        auth()->setDefaultDriver($this->guard);
        $this->middleware('auth:' . $this->guard);
    }

    /**
     * Store the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function getData($rootValue, array $args, GraphQLContext $context)
    {
        $product = $this->productRepository->findOrFail($args['product_id']);
        $customer = bagisto_graphql()->guard($this->guard)->user();
        if(!empty($customer)) {
            $product['customerFirstName'] = $customer->first_name ?? null;
            $product['customerLastName'] = $customer->last_name ?? null;
            $product['customerEmail'] = $customer->email ?? null;
            $product['customerPhone'] = $customer->phone ?? null;
            $customerAddress = $this->customerAddressRepository->where([["customer_id", $customer->id], ['default_address', 1]])->first();
            if(!empty($customerAddress)){
                $product['customerAddressFirstName'] = $customerAddress['first_name'];
                $product['customerAddressLastName'] = $customerAddress['last_name'];
                $product['customerAddressEmail'] = $customerAddress['email'];
                $product['customerAddress1'] = $customerAddress['address1'];
                $product['customerAddress2'] = $customerAddress['address2'];
                if($customer->phone == null) {
                    $product['customerPhone'] = $customerAddress['phone'] ?? null;
                }
                $product['customerAddressCity'] = $customerAddress['city'];
                $product['customerAddressState'] = $customerAddress['state'];
                $product['customerAddressCountry'] = $customerAddress['country'];
                $product['customerAddressPostCode'] = $customerAddress['postcode'];
            }
        }

        $result = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->addSelect('cart_items.quantity', 'orders.created_at', 'orders.id AS order_id')
            ->where('products.type', 'booking')
            ->where('orders.status', 'completed')
            ->groupBy('cart_items.product_id')->first();

        if(isset($result)) {
            $orderPlacedOn = null;
            if(isset($result->created_at))
                $orderPlacedOn = date("F d, Y, H:i", strtotime($result->created_at));

            $orderId = null;
            if(isset($result->order_id))
                $orderId = "#".$result->order_id;

            $product['noOfTickets'] = $result->quantity ?? 0;
            $product['orderId'] = $orderId;
            $product['orderPlacedOn'] = $orderPlacedOn;
            $product['paymentMethod'] = 'Credit Card';
        }
        return $product;
    }
}
