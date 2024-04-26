<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\ThankYouScreen;

use Carbon\Carbon;
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
use Webkul\Sales\Repositories\OrderRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Events\SendEventTicket;
use Webkul\Sales\Models\BookedEventTicketsHistory;
use Webkul\Customer\Repositories\CustomerDeliveryStatusRepository;

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
     * @param \Webkul\Sales\Repositories\OrderRepository $orderRepository
     * @param \Webkul\Sales\Models\BookedEventTicketsHistory $bookedTicketRepository
     * @param \Webkul\Customer\Repositories\CustomerDeliveryStatusRepository $customerDeliveryStatusRepository
     *
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected OrderRepository $orderRepository,
        protected CustomerAddressRepository $customerAddressRepository,
        protected TicketOrderRepository $ticketOrderRepository,
        protected CustomerRepository $customerRepository,
        protected BookedEventTicketsHistory $bookedTicketRepository,
        protected CustomerDeliveryStatusRepository $customerDeliveryStatusRepository
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


            $result_ticket_orders = DB::table('ticket_orders')
                ->where('ticket_orders.product_id', $product_id)
                ->where('ticket_orders.order_id', $args['order_id'])
                ->get();

            if(count($result_ticket_orders) > 0) {
                foreach ($result_ticket_orders as $ticket_order_index => $ticket_order) {
                    $ticketorders[$ticket_order_index] = [
                        'id' => $ticket_order->id,
                        'product_id' => $ticket_order->product_id,
                        'order_id' => $ticket_order->order_id,
                        'first_name' => $ticket_order->first_name,
                        'last_name' => $ticket_order->last_name,
                        'email' => $ticket_order->email,
                        'created_at' => $ticket_order->created_at,
                        'updated_at' => $ticket_order->updated_at,
                    ];
                    $product['thankYouScreenData'][$index]['ticketorders'] = $ticketorders;
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
                $product['thankYouScreenData'][$index]['noOfTickets'] = $result_event->quantity ?? 0;
            }
        }

        return $product;
    }

    public function getThankyouScreenData($rootValue, array $args, GraphQLContext $context)
    {
        $product = [];
        $customer = bagisto_graphql()->guard($this->guard)->user();
        DB::enableQueryLog();

        $subQuery = DB::table( DB::raw("(SELECT COUNT('x') FROM booked_event_tickets_history ct 
        WHERE ct.product_id = products.id and ct.orderId = orders.id) as name_counter") );
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $query
            ->join('booked_event_tickets_history', 'booked_event_tickets_history.product_id', '=', 'products.id')
            ->join('orders', 'booked_event_tickets_history.orderId', '=', 'orders.id')
            ->select('products.*','booked_event_tickets_history.product_id','orders.id as order_id','orders.customer_email as email','orders.customer_first_name as first_name','orders.customer_last_name as last_name','booked_event_tickets_history.id as orderedTicketId','booked_event_tickets_history.qrCode','booked_event_tickets_history.is_checkedIn','booked_event_tickets_history.ticket_id','orders.created_at','orders.updated_at',DB::raw("(SELECT COUNT('x') FROM booked_event_tickets_history ct 
   WHERE ct.product_id = products.id and ct.orderId = orders.id) as name_counter"))
            //->mergeBindings($subQuery)
            ->where('orders.id',$args['order_id'])
            ->where('booked_event_tickets_history.orderId',$args['order_id'])
            ->where('orders.customer_email',$customer->email)
            ->whereNotNull('products.id')
            ->orderBy('booked_event_tickets_history.product_id','desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
       return $query->paginate($count, ['*'], 'page', $page);
    }

    public function getQrScanningScreenData($rootValue, array $args, GraphQLContext $context)
    {
        $product = [];
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $res = $query->join('booked_event_tickets_history', 'booked_event_tickets_history.product_id', '=', 'products.id')
            ->join('orders', 'booked_event_tickets_history.orderId', '=', 'orders.id')
            ->select('products.*','booked_event_tickets_history.product_id','orders.id as order_id','orders.customer_email as email','orders.customer_first_name as first_name','orders.customer_last_name as last_name','booked_event_tickets_history.id as orderedTicketId','booked_event_tickets_history.qrCode','booked_event_tickets_history.is_checkedIn','booked_event_tickets_history.checkedIn_time','booked_event_tickets_history.ticket_id','orders.created_at','orders.updated_at')
            ->where('booked_event_tickets_history.id',$args['ticket_id'])->first();

        return $res;
    }

    public function getQRCheckInScreen($rootValue, array $args, GraphQLContext $context)
    {
        $data = [];
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $booking_ticket = $this->bookedTicketRepository->findOrFail($args['ticket_id']);
        if(!empty($booking_ticket))
        {
            $product = $this->productRepository->findOrFail($booking_ticket['product_id']);
            $booking = $product->booking_product;
            if($booking->event_pwd == $args['event_pwd'])
            {
                $this->bookedTicketRepository->where('id', $args['ticket_id'])->update(['is_checkedIn' => 1,'checkedIn_time'=> Carbon::now()]);
            }

        }


        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $res = $query->join('booked_event_tickets_history', 'booked_event_tickets_history.product_id', '=', 'products.id')
            ->join('orders', 'booked_event_tickets_history.orderId', '=', 'orders.id')
            ->select('products.*','booked_event_tickets_history.product_id','orders.id as order_id','orders.customer_email as email','orders.customer_first_name as first_name','orders.customer_last_name as last_name','booked_event_tickets_history.id as orderedTicketId','booked_event_tickets_history.qrCode','booked_event_tickets_history.is_checkedIn','booked_event_tickets_history.checkedIn_time','booked_event_tickets_history.ticket_id','orders.created_at','orders.updated_at')
            ->where('booked_event_tickets_history.id',$args['ticket_id'])->first();

        return $res;
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

        $result_ticket_orders = DB::table('ticket_orders')
            ->where('ticket_orders.product_id', $product_id)
            ->where('ticket_orders.order_id', $args['order_id'])
            ->get();
        if(count($result_ticket_orders) > 0) {
            foreach ($result_ticket_orders as $ticket_order_index => $ticket_order) {
                $ticketorders[$ticket_order_index] = [
                    'id' => $ticket_order->id,
                    'product_id' => $ticket_order->product_id,
                    'order_id' => $ticket_order->order_id,
                    'first_name' => $ticket_order->first_name,
                    'last_name' => $ticket_order->last_name,
                    'email' => $ticket_order->email,
                    'created_at' => $ticket_order->created_at,
                    'updated_at' => $ticket_order->updated_at,
                ];
                $product['ticketorders'] = $ticketorders;
            }
        }
        $prefix = DB::getTablePrefix();
        $result_event = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->addSelect('orders.created_at', 'orders.id AS order_id', 'orders.status as orderStatus', 'orders.customer_email')
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
        $product_id = $args['product_id'];
        $product = $this->productRepository->findOrFail($product_id);

        $order_id = $args['order_id'];
        $order = $this->orderRepository->findOrFail($order_id);

        $ordered_ticket_id= $args['ticket_id'];

        if(!empty($product) ) {
            $pdfName = 'order_'.$ordered_ticket_id.'.pdf';
            $response['url'] = Storage::disk('order')->url($pdfName);
           // $response['url'] = $responseData['url'];
        }
        else{
            throw new Exception('Ticket is not available');
        }

        return $response;
    }
    public function downloadInvoice($rootValue, array $args, GraphQLContext $context)
    {
        $order_id = $args['order_id'];
        $order = $this->orderRepository->findOrFail($order_id);
        if(!empty($order))
        {
            $pdfName = 'new_order_invoice_'.$order_id.'.pdf';
            $response['url'] = Storage::disk('order')->url($pdfName);
        }
        return $response;
    }

    public function emailEventTicket($rootValue, array $args, GraphQLContext $context)
    {
        $product_id = $args['product_id'];
        $product = $this->productRepository->findOrFail($product_id);
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $order_id = $args['order_id'];
        $order = $this->orderRepository->findOrFail($order_id);
        $ordered_ticket_id = $args['ticket_id'];
        $booking = $product->booking_product;
        $carbonFrom = Carbon::parse($booking->available_from);
        $carbonTo = Carbon::parse($booking->to);
        $formattedDateRange = $carbonFrom->format('F j') . ' & ' . $carbonTo->format('j') . ' | ' . $carbonFrom->format('g A') . ' - ' . $carbonTo->format('g A');




        $data['event_date'] = $formattedDateRange;
        $data['location'] = $booking->location.",".$booking->city;
        $image = $product->images;
        if(!empty($product) ) {

            $location= $booking->location . ' ' . $booking->city;
            $description='Your tickets are secured for the upcoming '.$product->sku.' event booked from Arte-Beat! – get ready to be captivated by the magic of the moment! Enjoy your event to the fullest!';
            $pdfName = 'order_'.$ordered_ticket_id.'.pdf';
            $response['path'] = Storage::disk('order')->path($pdfName);
            $data['pdfPath'] = $response['path'];
            $data['imagePath'] = 'storage/product/'.$image[0]['product_id'].'/'.$image[0]['path'];
            $data['event_name'] = $product->sku;
            $data['ticket_ref'] = $ordered_ticket_id;
            $data['productType'] = 'booking';
            $response['message'] = "Email sent successfully.";
            $formattedStartDateTime =date('Ymd\THis', strtotime($booking->available_from));
            $formattedEndDateTime =date('Ymd\THis', strtotime($booking->available_to));
//            dd($formattedStartDateTime,$formattedEndDateTime);
            $googleCalendarLink = 'https://www.google.com/calendar/render?action=TEMPLATE&text='.urlencode($product->sku). '&dates='. $formattedStartDateTime . '/' .$formattedEndDateTime . '&details=' . urlencode($description) . '&location=' . urlencode($location);
            $data['event_calender'] = $googleCalendarLink;

        }
        else{
            throw new Exception('Ticket is not available');
        }

        if(!empty($customer))
            SendEventTicket::dispatch($customer,$customer->email,$data);
        return $response;
    }

    public function emailInvoice($rootValue, array $args, GraphQLContext $context)
    {

        $customer = bagisto_graphql()->guard($this->guard)->user();
        $order = $this->orderRepository->findOrFail($args['order_id']);

        if(!empty($order) ) {
            $pdfName = 'new_order_invoice_'.$args['order_id'].'.pdf';
            $response['path'] = Storage::disk('order')->path($pdfName);
            $data['pdfPath'] = $response['path'];
            $data['productType'] = 'simple';
            $data['order_id'] = $args['order_id'];
            $response['message'] = "Email sent successfully.";
        }
        else{
            throw new Exception('Invoice is not available');
        }

        if(!empty($customer))
            SendEventTicket::dispatch($customer,$customer->email,$data);
        return $response;
    }

    public function getBookedMerchants($rootValue, array $args, GraphQLContext $context)
    {
        DB::enableQueryLog();
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query()
            ->leftJoin('cart_items', 'products.id', '=', 'cart_items.product_id')
            ->leftJoin('orders', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->addSelect('products.id', 'products.sku as productName', 'orders.created_at', 'cart_items.quantity','cart_items.id as cart_id', 'cart_items.ticket_id', 'orders.id AS order_id', 'cart_items.total as price', 'cart_items.base_price as basePrice', 'cart_items.quantity as purchasedQuantity','addresses.address1','addresses.address2','addresses.city','addresses.state','addresses.country','addresses.postcode')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('products.type', 'simple')
            ->whereNULL('products.product_type');
        $query->where('orders.id', $args['order_id']);
        $query->where('addresses.order_id', $args['order_id']);
        $query->where('addresses.address_type', 'order_shipping');
        $query->groupBy('cart_items.ticket_id');
        $query->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $res = $query->paginate($count, ['*'], 'page', $page);
        if(!empty($res))
        {
            foreach ($res as $key=>$value)
            {
                $deliveredProduct = $this->customerDeliveryStatusRepository->findOneWhere([
                    'cart_id' => $value['cart_id'],
                ]);

                if(!empty($deliveredProduct['status']))
                {
                    $res[$key]['deliveryStatus'] = 1 ;
                }
                else{
                    $res[$key]['deliveryStatus'] = 0;
                }
            }

        }

        return $res;
    }

    public function getBookedEvents($rootValue, array $args, GraphQLContext $context)
    {
        DB::enableQueryLog();
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query()
            ->leftJoin('cart_items', 'products.id', '=', 'cart_items.product_id')
            ->leftJoin('orders', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->addSelect('products.id', 'products.sku as productName', 'orders.created_at', 'cart_items.quantity', 'cart_items.ticket_id', 'orders.id AS order_id', 'cart_items.total as price', 'cart_items.base_price as basePrice', 'cart_items.quantity as purchasedQuantity')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('products.type', 'booking')
            ->whereNULL('products.product_type');
            $query->where('orders.id', $args['order_id']);
        $query->groupBy('cart_items.ticket_id');
        $query->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    public function getAllDetailsByOrder($rootValue, array $args, GraphQLContext $context)
    {
        DB::enableQueryLog();
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query()
            ->leftJoin('cart_items', 'products.id', '=', 'cart_items.product_id')
            ->leftJoin('orders', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->leftJoin('order_payment', 'order_payment.order_id', '=', 'orders.id') 
            ->addSelect('products.id', 'products.sku as productName', 'orders.created_at', 'cart_items.quantity', 'cart_items.type as productType', 'cart_items.ticket_id', 'orders.id AS order_id', 'cart_items.total as price', 'cart_items.base_price as basePrice', 'cart_items.quantity as purchasedQuantity', 'cart_items.id as cart_id','addresses.address1','addresses.address2','addresses.city','addresses.state','addresses.country','addresses.postcode', 'order_payment.id as order_payment_id', 'order_payment.method', 'order_payment.method_title', 'cart_items.total_with_commission', 'orders.shipping_description')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->whereIn('products.type', ['booking', 'simple'])
            ->whereNULL('products.product_type');
            $query->where('orders.id', $args['order_id']);
        $query->groupBy('cart_items.ticket_id');
        $query->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $res = $query->paginate($count, ['*'], 'page', $page);
        
        if(!empty($res))
        {
            foreach ($res as $key=>$value)
            {
                $deliveredProduct = $this->customerDeliveryStatusRepository->findOneWhere([
                    'cart_id' => $value['cart_id'],
                ]);

                if(!empty($deliveredProduct['status']))
                {
                    $res[$key]['deliveryStatus'] = 1 ;
                }
                else{
                    $res[$key]['deliveryStatus'] = 0;
                }
            }

        }

        return $res;
    }

    public function getOrderData($rootValue, array $args, GraphQLContext $context)
    {
        DB::enableQueryLog();
        $query = DB::table('orders');
        $query
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->leftJoin('order_payment', 'orders.id', '=', 'order_payment.order_id')
            ->leftJoin('cart', 'orders.cart_id', '=', 'cart.id')
            ->select('orders.*','order_payment.method as payment_method','orders.id as order_id','orders.customer_email','orders.customer_first_name','orders.customer_last_name', 'addresses.address1','addresses.address2','addresses.city','addresses.state','addresses.country','addresses.postcode', 'orders.total_qty_ordered', 'orders.grand_total', 'orders.shipping_description')
            ->selectRaw('SUM(cart.commission_amount + cart.transaction_fee) as total_fees')
            ->selectRaw('CONCAT(addresses.address1," ",addresses.address2) as address_info')
            ->selectRaw('CONCAT(orders.customer_first_name," ",orders.customer_last_name) as customer_name');
            $query->where('orders.id', $args['order_id']);
            $query->where('addresses.order_id', $args['order_id']);
        $order = $query->first();

        return $order;
    }

}
