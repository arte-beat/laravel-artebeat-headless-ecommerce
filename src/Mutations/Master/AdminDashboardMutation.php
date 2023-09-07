<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Webkul\Product\Repositories\ArtistRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Product\Models\Artist;

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

// - View Total Customers
// - View Total Event Organisers (merchants) or Person
// - View the total artist
// - View Total Event Bookings
// - View Total Merchandise Orders
// - View Total Events
// - View Total Revenue
// - View Total Event Revenue
// - View Total Merchandise Revenue
// - View Total Rating and Reviews
// - View Total Booking Enquiries

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

            $data['total_revenue'] = \Webkul\Sales\Models\OrderItem::sum('total_invoiced');

            $data['total_event_revenue'] = \Webkul\Sales\Models\OrderItem::with('product')
            ->whereHas('product', function ($query) {
                $query->where('product_type', '=', null);
            })
            ->sum('total_invoiced');

            $data['total_merchandise_revenue'] = \Webkul\Sales\Models\OrderItem::with('product')
            ->whereHas('product', function ($query) {
                $query->where('product_type', '=', null)
                ->where('type', '=', 'simple');
            })
            ->sum('total_invoiced');

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
}
