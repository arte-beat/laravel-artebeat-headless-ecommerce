<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\ThankYouScreen;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\TicketOrder;
use Webkul\Core\Contracts\Validations\Slug;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Product\Repositories\TicketOrderRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Customer\Repositories\CustomerAddressRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Events\SendEventTicket;

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
     * @param \Webkul\Customer\Repositories\CustomerRepository $customerRepository
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected CustomerAddressRepository $customerAddressRepository,
        protected TicketOrderRepository $ticketOrderRepository,
        protected CustomerRepository $customerRepository
    ) {
        $this->guard = 'api';
        auth()->setDefaultDriver($this->guard);
        $this->middleware('auth:' . $this->guard);
    }

    /**
     * @param $rootValue
     * @param array $args
     * @param GraphQLContext $context
     * @return array
     */
    public function getData($rootValue, array $args, GraphQLContext $context): array
    {
        $product = [];

        $queryMerch = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $queryMerch->whereHas('bookedAllProduct', function ($getBookedMerhants) use ($args) {
            $getBookedMerhants->where('orders.id', '=', $args['order_id']);
        });
        $resultMerch = $queryMerch->pluck('id');

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

        foreach ($resultMerch as $product_id) {
            $result = DB::table('orders')
                ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus')
                ->where('cart_items.product_id', $product_id)
                ->whereIn('orders.status', ['completed', 'pending'])
                ->groupBy('cart_items.product_id', 'cart_items.cart_id')
                ->first();

            if (isset($result)) {
                $orderPlacedOn = null;
                if (isset($result->created_at))
                    $orderPlacedOn = date("F d, Y, H:i", strtotime($result->created_at));

                $orderId = null;
                if (isset($result->order_id))
                    $orderId = "#" . $result->order_id;


                $product['orderStatus'] = $result->orderStatus ?? null;
                $product['orderId'] = $orderId;
                $product['orderPlacedOn'] = $orderPlacedOn;
                $product['paymentMethod'] = 'Credit Card';
            }
        }

        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $query->whereHas('bookedProduct', function ($getBookedEvents) use ($args) {
            $getBookedEvents->where('orders.id', '=', $args['order_id']);
        });
        $resultEvent = $query->pluck('id');
        foreach ($resultEvent as $index => $product_id) {
            $product['thankYouScreenData'][$index] = $this->productRepository->findOrFail($product_id);
            $prefix = DB::getTablePrefix();
            $result_event = DB::table('orders')
                ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus')
                ->selectRaw('SUM('.$prefix.'cart_items.quantity) as quantity')
                ->where('products.type', 'booking')
                ->where('cart_items.product_id', $product_id)
                ->whereIn('orders.status', ['completed', 'pending'])
                ->groupBy('cart_items.product_id', 'cart_items.cart_id')
                ->first();

            if($result_event){
                $product['thankYouScreenData'][$index]['noOfTickets'] = $result_event->quantity ?? 0;
            }
        }

        return $product;
    }

    public function getQRCodeData($rootValue, array $args, GraphQLContext $context)
    {
        $product = [];

        $product_id = $args['product_id'];
        $product = $this->productRepository->findOrFail($product_id);

        $result = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus', 'orders.customer_email')
            ->where('cart_items.product_id', $product_id)
            ->whereIn('orders.status', ['completed', 'pending'])
            ->groupBy('cart_items.product_id', 'cart_items.cart_id')
            ->first();

        if (isset($result)) {
            $orderPlacedOn = null;
            if (isset($result->created_at))
                $orderPlacedOn = date("F d, Y, H:i", strtotime($result->created_at));

            $orderId = null;
            if (isset($result->order_id))
                $orderId = "#" . $result->order_id;

            if (isset($result->customer_email)){
                $customer = $this->customerRepository->where("email", "=", $result->customer_email)->first();
                if (!empty($customer)) {
                    $product['customerFirstName'] = $customer->first_name ?? null;
                    $product['customerLastName'] = $customer->last_name ?? null;
                    $product['customerEmail'] = $customer->email ?? null;
                    $product['customerPhone'] = $customer->phone ?? null;
                    $customerAddress = $this->customerAddressRepository->where([["customer_id", $customer->id], ['default_address', 1]])->first();
                    if (!empty($customerAddress)) {
                        $product['customerAddressFirstName'] = $customerAddress['first_name'];
                        $product['customerAddressLastName'] = $customerAddress['last_name'];
                        $product['customerAddressEmail'] = $customerAddress['email'];
                        $product['customerAddress1'] = $customerAddress['address1'];
                        $product['customerAddress2'] = $customerAddress['address2'];
                        if ($customer->phone == null) {
                            $product['customerPhone'] = $customerAddress['phone'] ?? null;
                        }
                        $product['customerAddressCity'] = $customerAddress['city'];
                        $product['customerAddressState'] = $customerAddress['state'];
                        $product['customerAddressCountry'] = $customerAddress['country'];
                        $product['customerAddressPostCode'] = $customerAddress['postcode'];
                    }
                }

                $product['orderStatus'] = $result->orderStatus ?? null;
                $product['orderId'] = $orderId;
                $product['orderPlacedOn'] = $orderPlacedOn;
                $product['paymentMethod'] = 'Credit Card';
            }
        }

        $prefix = DB::getTablePrefix();
        $result_event = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus')
            ->selectRaw('SUM('.$prefix.'cart_items.quantity) as quantity')
            ->where('products.type', 'booking')
            ->where('cart_items.product_id', $product_id)
            ->whereIn('orders.status', ['completed', 'pending'])
            ->groupBy('cart_items.product_id', 'cart_items.cart_id')
            ->first();

        if($result_event){
            $product['noOfTickets'] = $result_event->quantity ?? 0;
        }

        return $product;
    }


    public function downloadTicket($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $query->whereHas('bookedProduct', function ($getBookedMerhants) use ($args) {
            $getBookedMerhants->where('orders.id', '=', $args['order_id']);
        });
        $result = $query->pluck('id');
        foreach ($result as $index => $product_id) {
            $product[$index] = $this->productRepository->findOrFail($product_id);
            $customer = bagisto_graphql()->guard($this->guard)->user();
            if(!empty($customer)) {
                $product[$index]['customerFirstName'] = $customer->first_name ?? null;
                $product[$index]['customerLastName'] = $customer->last_name ?? null;
                $product[$index]['customerEmail'] = $customer->email ?? null;
                $product[$index]['customerPhone'] = $customer->phone ?? null;
                $customerAddress = $this->customerAddressRepository->where([["customer_id", $customer->id], ['default_address', 1]])->first();
                if(!empty($customerAddress)){
                    $product[$index]['customerAddressFirstName'] = $customerAddress['first_name'];
                    $product[$index]['customerAddressLastName'] = $customerAddress['last_name'];
                    $product[$index]['customerAddressEmail'] = $customerAddress['email'];
                    $product[$index]['customerAddress1'] = $customerAddress['address1'];
                    $product[$index]['customerAddress2'] = $customerAddress['address2'];
                    if($customer->phone == null) {
                        $product[$index]['customerPhone'] = $customerAddress['phone'] ?? null;
                    }
                    $product[$index]['customerAddressCity'] = $customerAddress['city'];
                    $product[$index]['customerAddressState'] = $customerAddress['state'];
                    $product[$index]['customerAddressCountry'] = $customerAddress['country'];
                    $product[$index]['customerAddressPostCode'] = $customerAddress['postcode'];
                }
            }

            $prefix = DB::getTablePrefix();
            $result = DB::table('orders')
                ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus')
                ->selectRaw('SUM('.$prefix.'cart_items.quantity) as quantity')
                ->where('products.type', 'booking')
                ->where('cart_items.product_id', $product_id)
                ->whereIn('orders.status', ['completed', 'pending'])
                ->groupBy('cart_items.product_id', 'cart_items.cart_id')
                ->first();

            if(isset($result)) {
                $orderPlacedOn = null;
                if(isset($result->created_at))
                    $orderPlacedOn = date("F d, Y, H:i", strtotime($result->created_at));

                $orderId = null;
                if(isset($result->order_id))
                    $orderId = "#".$result->order_id;

                $product[$index]['noOfTickets'] = $result->quantity ?? 0;
                $product[$index]['orderStatus'] = $result->orderStatus ?? null;
                $product[$index]['orderId'] = $orderId;
                $product[$index]['order_id'] = $result->order_id;
                $product[$index]['orderPlacedOn'] = $orderPlacedOn;
                $product[$index]['paymentMethod'] = 'Credit Card';
            }
        }

        $responseData = $this->productRepository->downloadTicket($product);
        $response['url'] = $responseData['url'];
        return $response;
    }
    public function downloadInvoice($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $query->whereHas('bookedProduct', function ($getBookedMerhants) use ($args) {
            $getBookedMerhants->where('orders.id', '=', $args['order_id']);
        });
        $result = $query->pluck('id');
        foreach ($result as $index => $product_id) {
            $product[$index] = $this->productRepository->findOrFail($product_id);
            $customer = bagisto_graphql()->guard($this->guard)->user();
            if(!empty($customer)) {
                $product[$index]['customerFirstName'] = $customer->first_name ?? null;
                $product[$index]['customerLastName'] = $customer->last_name ?? null;
                $product[$index]['customerEmail'] = $customer->email ?? null;
                $product[$index]['customerPhone'] = $customer->phone ?? null;
                $customerAddress = $this->customerAddressRepository->where([["customer_id", $customer->id], ['default_address', 1]])->first();
                if(!empty($customerAddress)){
                    $product[$index]['customerAddressFirstName'] = $customerAddress['first_name'];
                    $product[$index]['customerAddressLastName'] = $customerAddress['last_name'];
                    $product[$index]['customerAddressEmail'] = $customerAddress['email'];
                    $product[$index]['customerAddress1'] = $customerAddress['address1'];
                    $product[$index]['customerAddress2'] = $customerAddress['address2'];
                    if($customer->phone == null) {
                        $product[$index]['customerPhone'] = $customerAddress['phone'] ?? null;
                    }
                    $product[$index]['customerAddressCity'] = $customerAddress['city'];
                    $product[$index]['customerAddressState'] = $customerAddress['state'];
                    $product[$index]['customerAddressCountry'] = $customerAddress['country'];
                    $product[$index]['customerAddressPostCode'] = $customerAddress['postcode'];
                }
            }

            $prefix = DB::getTablePrefix();
            $result = DB::table('orders')
                ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus')
                ->selectRaw('SUM('.$prefix.'cart_items.quantity) as quantity')
                ->where('products.type', 'booking')
                ->where('cart_items.product_id', $product_id)
                ->whereIn('orders.status', ['completed', 'pending'])
                ->groupBy('cart_items.product_id', 'cart_items.cart_id')
                ->first();

            if(isset($result)) {
                $orderPlacedOn = null;
                if(isset($result->created_at))
                    $orderPlacedOn = date("F d, Y, H:i", strtotime($result->created_at));

                $orderId = null;
                if(isset($result->order_id))
                    $orderId = "#".$result->order_id;

                $product[$index]['noOfTickets'] = $result->quantity ?? 0;
                $product[$index]['orderStatus'] = $result->orderStatus ?? null;
                $product[$index]['orderId'] = $orderId;
                $product[$index]['order_id'] = $result->order_id;
                $product[$index]['orderPlacedOn'] = $orderPlacedOn;
                $product[$index]['paymentMethod'] = 'Credit Card';
            }
        }

        $responseData = $this->productRepository->downloadTicket($product);
        $response['url'] = $responseData['url'];
        return $response;
    }
    public function emailEventTicket($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $query->whereHas('bookedProduct', function ($getBookedMerhants) use ($args) {
            $getBookedMerhants->where('orders.id', '=', $args['order_id']);
        });
        $result = $query->pluck('id');
        foreach ($result as $index => $product_id) {
            $product[$index] = $this->productRepository->findOrFail($product_id);
            $customer = bagisto_graphql()->guard($this->guard)->user();
            if(!empty($customer)) {
                $product[$index]['customerFirstName'] = $customer->first_name ?? null;
                $product[$index]['customerLastName'] = $customer->last_name ?? null;
                $product[$index]['customerEmail'] = $customer->email ?? null;
                $product[$index]['customerPhone'] = $customer->phone ?? null;
                $customerAddress = $this->customerAddressRepository->where([["customer_id", $customer->id], ['default_address', 1]])->first();
                if(!empty($customerAddress)){
                    $product[$index]['customerAddressFirstName'] = $customerAddress['first_name'];
                    $product[$index]['customerAddressLastName'] = $customerAddress['last_name'];
                    $product[$index]['customerAddressEmail'] = $customerAddress['email'];
                    $product[$index]['customerAddress1'] = $customerAddress['address1'];
                    $product[$index]['customerAddress2'] = $customerAddress['address2'];
                    if($customer->phone == null) {
                        $product[$index]['customerPhone'] = $customerAddress['phone'] ?? null;
                    }
                    $product[$index]['customerAddressCity'] = $customerAddress['city'];
                    $product[$index]['customerAddressState'] = $customerAddress['state'];
                    $product[$index]['customerAddressCountry'] = $customerAddress['country'];
                    $product[$index]['customerAddressPostCode'] = $customerAddress['postcode'];
                }
            }

            $prefix = DB::getTablePrefix();
            $result = DB::table('orders')
                ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus')
                ->selectRaw('SUM('.$prefix.'cart_items.quantity) as quantity')
                ->where('products.type', 'booking')
                ->where('cart_items.product_id', $product_id)
                ->whereIn('orders.status', ['completed', 'pending'])
                ->groupBy('cart_items.product_id', 'cart_items.cart_id')
                ->first();

            if(isset($result)) {
                $orderPlacedOn = null;
                if(isset($result->created_at))
                    $orderPlacedOn = date("F d, Y, H:i", strtotime($result->created_at));

                $orderId = null;
                if(isset($result->order_id))
                    $orderId = "#".$result->order_id;

                $product[$index]['noOfTickets'] = $result->quantity ?? 0;
                $product[$index]['orderStatus'] = $result->orderStatus ?? null;
                $product[$index]['orderId'] = $orderId;
                $product[$index]['order_id'] = $result->order_id;
                $product[$index]['orderPlacedOn'] = $orderPlacedOn;
                $product[$index]['paymentMethod'] = 'Credit Card';
            }
        }

        $responseData = $this->productRepository->downloadTicket($product);
        $response['url'] = $responseData['url'];
        $files = [$responseData['url']];
        $path = $responseData['path'];
        SendEventTicket::dispatch($customer, $path);
        return $response;
    }
    public function emailInvoice($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $query->whereHas('bookedProduct', function ($getBookedMerhants) use ($args) {
            $getBookedMerhants->where('orders.id', '=', $args['order_id']);
        });
        $result = $query->pluck('id');
        foreach ($result as $index => $product_id) {
            $product[$index] = $this->productRepository->findOrFail($product_id);
            $customer = bagisto_graphql()->guard($this->guard)->user();
            if(!empty($customer)) {
                $product[$index]['customerFirstName'] = $customer->first_name ?? null;
                $product[$index]['customerLastName'] = $customer->last_name ?? null;
                $product[$index]['customerEmail'] = $customer->email ?? null;
                $product[$index]['customerPhone'] = $customer->phone ?? null;
                $customerAddress = $this->customerAddressRepository->where([["customer_id", $customer->id], ['default_address', 1]])->first();
                if(!empty($customerAddress)){
                    $product[$index]['customerAddressFirstName'] = $customerAddress['first_name'];
                    $product[$index]['customerAddressLastName'] = $customerAddress['last_name'];
                    $product[$index]['customerAddressEmail'] = $customerAddress['email'];
                    $product[$index]['customerAddress1'] = $customerAddress['address1'];
                    $product[$index]['customerAddress2'] = $customerAddress['address2'];
                    if($customer->phone == null) {
                        $product[$index]['customerPhone'] = $customerAddress['phone'] ?? null;
                    }
                    $product[$index]['customerAddressCity'] = $customerAddress['city'];
                    $product[$index]['customerAddressState'] = $customerAddress['state'];
                    $product[$index]['customerAddressCountry'] = $customerAddress['country'];
                    $product[$index]['customerAddressPostCode'] = $customerAddress['postcode'];
                }
            }

            $prefix = DB::getTablePrefix();
            $result = DB::table('orders')
                ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus')
                ->selectRaw('SUM('.$prefix.'cart_items.quantity) as quantity')
                ->where('products.type', 'booking')
                ->where('cart_items.product_id', $product_id)
                ->whereIn('orders.status', ['completed', 'pending'])
                ->groupBy('cart_items.product_id', 'cart_items.cart_id')
                ->first();

            if(isset($result)) {
                $orderPlacedOn = null;
                if(isset($result->created_at))
                    $orderPlacedOn = date("F d, Y, H:i", strtotime($result->created_at));

                $orderId = null;
                if(isset($result->order_id))
                    $orderId = "#".$result->order_id;

                $product[$index]['noOfTickets'] = $result->quantity ?? 0;
                $product[$index]['orderStatus'] = $result->orderStatus ?? null;
                $product[$index]['orderId'] = $orderId;
                $product[$index]['order_id'] = $result->order_id;
                $product[$index]['orderPlacedOn'] = $orderPlacedOn;
                $product[$index]['paymentMethod'] = 'Credit Card';
            }
        }

        $responseData = $this->productRepository->downloadTicket($product);
        $response['url'] = $responseData['url'];
        $files = [$responseData['url']];
        $path = $responseData['path'];
        SendEventTicket::dispatch($customer, $path);
        return $response;
    }

    public function getBookedMerchants($rootValue, array $args, GraphQLContext $context)
    {
        DB::enableQueryLog();
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query()
            ->leftJoin('cart_items', 'products.id', '=', 'cart_items.product_id')
            ->leftJoin('orders', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->addSelect('products.id', 'orders.created_at', 'cart_items.quantity', 'cart_items.ticket_id', 'orders.id AS order_id', 'cart_items.total as price', 'cart_items.base_price as basePrice', 'cart_items.quantity as purchasedQuantity')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('products.type', 'simple')
            ->whereNULL('products.product_type');
            $query->where('orders.id', $args['order_id']);
        $query->groupBy('cart_items.ticket_id');
        $query->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }
}
