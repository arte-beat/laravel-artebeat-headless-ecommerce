<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Illuminate\Support\Facades\Validator;
use Webkul\Admin\Http\Controllers\Controller;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReportsMutation extends Controller
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
    public function getCustomerReport($rootValue, array $args, GraphQLContext $context)
    {
        $id = $args['id'];
        $customer = \Webkul\Customer\Models\Customer::where('id', $id)->first();

        $data = new \stdClass();
        
        if(!$customer) {
            throw new Exception('Customer not found');
        }
        try {

            // User/Event Organizer Name
            // User Type
            // Email Address
            // Attended event 
            // !Purchased Tickets
            // Orders merchandise
            // Total Revenue

            $data->name = $customer->first_name . ' ' . $customer->last_name;
            $data->type = $customer->customer_type == 1 ? 'Customer' : 'Event Organiser';
            $data->email = $customer->email;

            $data->attendedEvent = \Webkul\Product\Models\Product::where('type','booking')
            ->where('product_type',null)
            ->count();

            // ! purchasedTickets: Int // Webkul\Product\Models\EventTickets
            // $data->purchasedTickets = \Webkul\Product\Models\EventTickets::with('product')
            // ->where('customer_id', $id)->count();

            $data->orderedMerchandise = \Webkul\Sales\Models\OrderItem::with('product','order')
            ->whereHas('product', function ($query) {
                $query->where('product_type', '=', null)
                ->where('type', '=', 'simple');
            })->whereHas('order', function ($query) use ($id) {
                
                $query->with('customer')->where('customer_id', '=', $id);
            })
            ->count();
            
            $data->totalRevenue =  \Webkul\Sales\Models\OrderItem::with('product','order')
            ->whereHas('order', function ($query) use ($id) {
                $query->with('customer')->where('customer_id', '=', $id);
            })
            ->sum('total_invoiced');

            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getEventReport($rootValue, array $args, GraphQLContext $context)
    {
        $id = $args['id'];
        $event = \Webkul\Product\Models\Product::where('id', $id)->first();

        $data = new \stdClass();
        
        if(!$event) {
            throw new Exception('Event not found');
        }
        try {
            // name: String
            // !bookingQuantity: Int
            // orderedMerchandise: Int
            // totalRevenue: Float

            $data->name = $event->sku;

            // ! bookingQuantity: Int
            $data->bookingQuantity = \Webkul\Product\Models\Product::where('type','booking')
            ->where('product_type',null)->count();

            $data->orderedMerchandise = \Webkul\Sales\Models\OrderItem::with('product','order')
            ->whereHas('product', function ($query) use ($id){
                $query->where('id', '=', $id)
                ->where('product_type', '=', null)
                ->where('type', '=', 'simple');
            })->whereHas('order', function ($query) use ($id) {
                $query->with('customer')->where('customer_id', '=', $id);
            })
            ->count();
            
            $data->totalRevenue =  \Webkul\Sales\Models\OrderItem::where('product_id', '=', $id)
            ->sum('total_invoiced');

            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getBookingReport($rootValue, array $args, GraphQLContext $context)
    {
        $id = $args['id'];
        $event = \Webkul\Product\Models\Product::where('id', $id)->first();

        $data = new \stdClass();
        
        if(!$event) {
            throw new Exception('Event not found');
        }
        try {
            // !bookingInformation: BookingProduct
            // !customerInformation: Customer
            // !paymentAmount : Float
            // !transactionId: String
            // !paymentStatus: String
            // !paymentMethod: String
            // !paymentDate: String @rename(attribute: "created_at")
           
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
