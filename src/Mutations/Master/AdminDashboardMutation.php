<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Webkul\Admin\Http\Controllers\Controller;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AdminDashboardMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\ArtistRepository  $artistRepository
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
    public function getStatistics($rootValue, array $args, GraphQLContext $context)
    {
        try {
            $data['total_customers'] = \Webkul\Customer\Models\Customer::where('customer_type', 1)->count();
            $data['total_event_organisers'] = \Webkul\Customer\Models\Customer::where('customer_type', 2)->count();
            $data['total_artists'] = \Webkul\Product\Models\Artist::count();
            $data['total_event_bookings'] = \Webkul\Product\Models\Product::where('type','booking')->where('product_type',null)->count();
            $data['total_events'] = \Webkul\Product\Models\Product::count();

            $data['total_merchandise_orders'] = \Webkul\Sales\Models\OrderItem::with('product')
            ->whereHas('product', function ($query) {
                $query->where('product_type', '=', null)
                ->where('type', '=', 'simple');
            })
            ->count();

            $data['total_revenue'] = \Webkul\Sales\Models\OrderItem::sum('total_with_commission');

            $data['total_event_revenue'] = \Webkul\Sales\Models\OrderItem::with('product')
            ->whereHas('product', function ($query) {
                $query->where('product_type', '=', null);
            })
            ->sum('total_with_commission');

            $data['total_merchandise_revenue'] = \Webkul\Sales\Models\OrderItem::with('product')
            ->whereHas('product', function ($query) {
                $query->where('product_type', '=', null)
                ->where('type', '=', 'simple');
            })
            ->sum('total_with_commission');

            $data['total_rating_and_reviews'] = \Webkul\Product\Models\ProductReview::count();
            $data['total_booking_enquiries'] = \Webkul\BookingProduct\Models\BookingProduct::count();
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getLatestTenEventBookingRequests($rootValue, array $args, GraphQLContext $context)
    {
        try {
            $data = \Webkul\Product\Models\Product::where('type','booking')->where('product_type',null)->orderBy('id', 'desc')->take(10)->get();
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

    }

    public function gerLatestTenCustomers($rootValue, array $args, GraphQLContext $context)
    {
        try {
            $data = \Webkul\Customer\Models\Customer::orderBy('id', 'desc')->take(10)->get();
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getLatestTenEvents($rootValue, array $args, GraphQLContext $context)
    {
        try {
            $data = \Webkul\Product\Models\Product::orderBy('id', 'desc')->take(10)->get();
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getLatestTenPaymentAndTransactions($rootValue, array $args, GraphQLContext $context){
        try {
            $data = \Webkul\Sales\Models\Order::orderBy('id', 'desc')->take(10)->get();
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
