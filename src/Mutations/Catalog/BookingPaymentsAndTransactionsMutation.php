<?php

namespace Webkul\GraphQLAPI\Mutations\Catalog;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
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
        $query->orderBy('id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $result = $query->paginate($count,['*'],'page',$page);
        foreach ($result as $index => $item) {
            $result[$index]['mode_of_payment'] = 'Credit Card';
        }
        return $result;
    }

    public function getParticularBookingPaymentsAndTransactionsResponse($rootValue, array $args, GraphQLContext $context)
    {
        $result = $this->orderRepository->findOrFail($args['id']);
        $result['mode_of_payment'] = 'Credit Card';
        return $result;
    }
}
