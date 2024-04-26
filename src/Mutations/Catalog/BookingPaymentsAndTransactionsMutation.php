<?php

namespace Webkul\GraphQLAPI\Mutations\Catalog;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Webkul\Product\Models\Product;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Sales\Repositories\OrderRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class BookingPaymentsAndTransactionsMutation extends Controller
{
    /**
     * @var int
     */
    protected $id;

    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Sales\Repositories\OrderRepository $orderRepository
     * @return void
     */
    public function __construct(
        protected OrderRepository $orderRepository
    )
    {
        $this->guard = 'admin-api';
        auth()->setDefaultDriver($this->guard);
        $this->_config = request('_config');
    }


    public function getAllBookingPaymentsAndTransactionsResponse($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Sales\Models\Order::query();
//        $query->leftJoin('cart_items', 'orders.cart_id', '=', 'cart_items.cart_id');
//        $query->leftJoin('products', 'cart_items.product_id', '=', 'products.id');
        $query->addSelect("*");
        $query->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name");
        $query->with('paymentMethodCards');
        if(!empty($args['input']['customer_name'])){
            $query->having("customer_name", "like", "%" .  $args['input']['customer_name'] . "%");
        }
        if(!empty($args['input']['email'])){
            $query->where('orders.customer_email', 'like', '%' .  $args['input']['email'] . '%');
        }
        if(!empty($args['input']['status'])){
            $query->where('orders.status', 'like', '%' .  $args['input']['status'] . '%');
        }
        $query->orderBy('orders.id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $result = $query->paginate($count,['*'],'page',$page);

        foreach ($result as $index => $item) {
            $result[$index]['order_id'] = '#'.$item['id'];
        }

        return $result;
    }

    public function getParticularBookingPaymentsAndTransactionsResponse($rootValue, array $args, GraphQLContext $context)
    {
        $order = $this->orderRepository->findOrFail($args['id']);

        $query = \Webkul\Sales\Models\Order::query();
        $query->addSelect("*");
        $query->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name")
            ->where('orders.id', $args['id']);
//        $query->leftJoin('cart_items', 'orders.cart_id', '=', 'cart_items.cart_id')
//            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
//            ->addSelect('orders.*','products.sku as event_name')
//            ->where('orders.id', $args['id'])
//            ->groupBy('orders.cart_id')
//            ->orderBy('orders.id', 'desc');
        $result = $query->first();
        $result['order_id'] = '#'.$result['id'];
        $result['mode_of_payment'] = 'Credit Card';
        return $result;
    }

    public function updateOrderStatus($rootValue, array $args, GraphQLContext $context)
    {
        $order_id   = $args['order_id'];
        $status     = $args['status'];

        $updatedData['status'] = $status;
        if ($order = $this->orderRepository->update($updatedData, $order_id)) {
            return [
                'status' => $status,
                'success' => "Order status updated successfully."
            ];
        } else {
            throw new CustomException('Error while updating order status.','Error while updating order status.');
        }
    }

    public function getTransactionListByCustomerId($rootValue, array $args, GraphQLContext $context)
    {
        $customer_id = $args['input']['id'];

        $query = \Webkul\Sales\Models\Order::query()->where('orders.customer_id', '=', $customer_id);
//        $query->leftJoin('cart_items', 'orders.cart_id', '=', 'cart_items.cart_id');
//        $query->leftJoin('products', 'cart_items.product_id', '=', 'products.id');
        $query->addSelect("*");
        $query->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name");
        $query->with('paymentMethodCards');
        if(!empty($args['input']['customer_name'])){
            $query->having("customer_name", "like", "%" .  $args['input']['customer_name'] . "%");
        }
        if(!empty($args['input']['email'])){
            $query->where('orders.customer_email', 'like', '%' .  $args['input']['email'] . '%');
        }
        if(!empty($args['input']['status'])){
            $query->where('orders.status', 'like', '%' .  $args['input']['status'] . '%');
        }
        $query->orderBy('orders.id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $result = $query->paginate($count,['*'],'page',$page);

        foreach ($result as $index => $item) {
            $result[$index]['order_id'] = '#'.$item['id'];
        }

        return $result;
    }
}
