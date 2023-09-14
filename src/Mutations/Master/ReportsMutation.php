<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Webkul\Admin\Http\Controllers\Controller;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReportsMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\ArtistRepository $artistRepository
     * @return void
     */
    public function __construct()
    {
        $this->guard = 'admin-api';
        auth()->setDefaultDriver($this->guard);
        $this->_config = request('_config');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCustomerReport($rootValue, array $args, GraphQLContext $context)
    {
        $prefix = DB::getTablePrefix();
        $query = \Webkul\Sales\Models\Order::query();
        $query->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->leftJoin('customers', 'products.owner_id', '=', 'customers.id')
            ->Select('customers.first_name', 'customers.last_name', 'customers.id', 'customers.email', 'customers.customer_type as usertype')
            ->SelectRaw('SUM(' . $prefix . 'cart_items.quantity) as event_total_sold , SUM(' . $prefix . 'cart_items.base_total) as event_total_sale')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('products.type', 'booking')
            ->groupBy('orders.customer_id')->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $all_result = $query->paginate($count, ['*'], 'page', $page);
        if (!empty($all_result)) {
            foreach ($all_result as $index => $item) {
                $merch_query = \Webkul\Sales\Models\Order::query();
                $res = $merch_query->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                    ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                    ->leftJoin('customers', 'products.owner_id', '=', 'customers.id')
                    ->Select('customers.first_name', 'customers.last_name')
                    ->SelectRaw('SUM(' . $prefix . 'cart_items.quantity) as total_sold , SUM(' . $prefix . 'cart_items.base_total) as total_sale')
                    ->whereIn('orders.status', ['completed', 'pending'])
                    ->where('products.type', 'simple')
                    ->groupBy('orders.customer_id')->first();
                $all_result[$index]['merchant_total_sold'] = $res['total_sold'];
                $all_result[$index]['merchant_total_sale'] = $res['total_sale'];
            }
        }
        return $all_result;

    }

    public function getEventReport($rootValue, array $args, GraphQLContext $context)
    {
        $prefix = DB::getTablePrefix();
        $query = \Webkul\Sales\Models\Order::query();
        $query->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->leftJoin('customers', 'products.owner_id', '=', 'customers.id')
            ->Select('products.type', 'products.owner_id', 'customers.first_name', 'customers.last_name', 'products.owner_type', 'products.parent_id', 'products.id', 'products.sku as eventName')
            ->SelectRaw('SUM(' . $prefix . 'cart_items.quantity) as event_total_sold , SUM(' . $prefix . 'cart_items.base_total) as event_total_sale')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('products.type', 'booking')
            ->groupBy('cart_items.product_id')->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $all_result = $query->paginate($count, ['*'], 'page', $page);


        return $all_result;
    }


    public function getBookingReport($rootValue, array $args, GraphQLContext $context)
    {

        $prefix = DB::getTablePrefix();
        $query = \Webkul\Sales\Models\Order::query();
        $query->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->leftJoin('customers', 'products.owner_id', '=', 'customers.id')
            ->Select('products.type', 'products.owner_id', 'customers.first_name', 'customers.last_name', 'products.owner_type', 'products.parent_id', 'products.id', 'products.sku as eventName')
            ->SelectRaw('SUM(' . $prefix . 'cart_items.quantity) as event_total_sold , SUM(' . $prefix . 'cart_items.base_total) as event_total_sale')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('products.type', 'booking')
            ->groupBy('cart_items.product_id')->orderBy('orders.id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $all_result = $query->paginate($count, ['*'], 'page', $page);

        if (!empty($all_result)) {
            foreach ($all_result as $index => $item) {
                $merch_query = \Webkul\Sales\Models\Order::query();
                $res = $merch_query->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
                    ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
                    ->SelectRaw('SUM(' . $prefix . 'cart_items.quantity) as total_sold , SUM(' . $prefix . 'cart_items.base_total) as total_sale')
                    ->whereIn('orders.status', ['completed', 'pending'])
                    ->where('products.type', 'simple')
                    ->where('products.parent_id', $item['id'])
                    ->groupBy('products.parent_id')->first();
                if(!empty($res))
                {

                    $all_result[$index]['merchant_total_sold'] = $res['total_sold'];
                    $all_result[$index]['merchant_total_sale'] = $res['total_sale'];

                }

            }

        }
        return $all_result;
    }

}
