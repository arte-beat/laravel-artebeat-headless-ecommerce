<?php

namespace Webkul\GraphQLAPI\Mutations\Customer;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Mail\NewCustomerNotification;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Customer\Models\Customer;
use App\Events\SendAccountBlockEvent;
use Illuminate\Support\Facades\DB;

class CustomerMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Customer\Repositories\CustomerRepository  $customerRepository
     * @param  \Webkul\Customer\Repositories\CustomerGroupRepository  $customerGroupRepository
     * @return void
     */
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected CustomerGroupRepository $customerGroupRepository
    )
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
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'first_name'        => 'string|required',
            'last_name'         => 'string|required',
            'gender'            => 'required',
            'email'             => 'required|unique:customers,email',
            'date_of_birth'     => 'string|before:today',
//            'customer_group_id' => 'required|numeric',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $password = rand(100000, 10000000);

        $data['password'] = bcrypt($password);

        $data['is_verified'] = 1;

        $data['date_of_birth'] = (isset($data['date_of_birth']) && $data['date_of_birth']) ? Carbon::createFromTimeString(str_replace('/', '-', $data['date_of_birth']) . '00:00:01')->format('Y-m-d') : '';

        try {
            Event::dispatch('customer.registration.before');
    
            $customer = $this->customerRepository->create($data);
    
            Event::dispatch('customer.registration.after', $customer);
            
            $configKey = 'emails.general.notifications.emails.general.notifications.customer';
            if (core()->getConfigData($configKey)) {
                Mail::queue(new NewCustomerNotification($customer, $password));
            }
            
            return $customer;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];
        $validator = Validator::make($data, [
            'first_name'        => 'string|required',
            'last_name'         => 'string|required',
//            'gender'            => 'required',
            'date_of_birth'     => 'date|before:today',
//          'customer_group_id' => 'required|numeric',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            if(isset($data['status']))
            {
               if($data['status'])
               {
                   $data['status'] = 1;
               }
               else{
                   $data['status'] = 0;
               }
            }

            Event::dispatch('customer.customer.update.before');

            $customer = $this->customerRepository->update($data, $id);
            if( isset($data['status']) && $data['status'] == 0)
            {
                SendAccountBlockEvent::dispatch($customer, $customer['email'],'customer');
            }

            Event::dispatch('customer.customer.update.after', $customer);

            return $customer;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];

        $customer = $this->customerRepository->findOrFail($id);

        try {

            if (! $this->customerRepository->checkIfCustomerHasOrderPendingOrProcessing($customer)) {
                Event::dispatch('customer.customer.delete.before', $id);

                $this->customerRepository->delete($id);

                Event::dispatch('customer.customer.delete.after', $id);

                return ['success' => trans('admin::app.response.delete-success', ['name' => 'Customer'])];
            
            } else {
                throw new Exception(trans('admin::app.response.order-pending', ['name' => 'Customer']));

            }
        } catch(\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Customer']));
        }
    }

    public function filterCustomer($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Customer\Models\Customer::query();

        if(isset($args['input']['name']) && !empty($args['input']['name'])) {
            $query->where(function ($nameQuery) use ($args) {
                $nameQuery->where('customers.first_name', 'LIKE', '%' . $args['input']['name'] . '%');
                $nameQuery->orWhere('customers.last_name', 'LIKE', '%' . $args['input']['name'] . '%');
            });
        }
        if(isset($args['input']['phone']) && !empty($args['input']['phone'])) {
            $query->where('phone', 'like', '%' . urldecode($args['input']['phone']) . '%');
        }
        if(isset($args['input']['email']) && !empty($args['input']['email'])) {
            $query->where('email', 'like', '%' . urldecode($args['input']['email']) . '%');
        }
        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }

    public function filterCustomerEvents($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Customer\Models\Customer::query();

        $query->select("customers.*")->where('customer_type', 2);

        if(isset($args['input']['name']) && !empty($args['input']['name'])) {
            $query->where(function ($nameQuery) use ($args) {
                $nameQuery->where('customers.first_name', 'LIKE', '%' . $args['input']['name'] . '%');
                $nameQuery->orWhere('customers.last_name', 'LIKE', '%' . $args['input']['name'] . '%');
            });
        }
        if(isset($args['input']['phone']) && !empty($args['input']['phone'])) {
            $query->where('customers.phone', 'like', '%' . urldecode($args['input']['phone']) . '%');
        }
        if(isset($args['input']['email']) && !empty($args['input']['email'])) {
            $query->where('customers.email', 'like', '%' . urldecode($args['input']['email']) . '%');
        }
        $query->join('products', 'customers.id', '=', 'products.owner_id')->where('event_status', '=', '1');
        $query->groupBy('customers.id');
        $query->orderBy('customers.id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }

    public function filterCustomerTransaction($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Customer\Models\Customer::query();
        $query->select("customers.*");

        if(isset($args['input']['name']) && !empty($args['input']['name'])) {
            $query->where(function ($nameQuery) use ($args) {
                $nameQuery->where('customers.first_name', 'LIKE', '%' . $args['input']['name'] . '%');
                $nameQuery->orWhere('customers.last_name', 'LIKE', '%' . $args['input']['name'] . '%');
            });
        }
        if(isset($args['input']['phone']) && !empty($args['input']['phone'])) {
            $query->where('phone', 'like', '%' . urldecode($args['input']['phone']) . '%');
        }
        if(isset($args['input']['email']) && !empty($args['input']['email'])) {
            $query->where('email', 'like', '%' . urldecode($args['input']['email']) . '%');
        }

        $query->join('orders', 'customers.id', '=', 'orders.customer_id');
        $query->groupBy('customers.id');
        $query->orderBy('customers.id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }

    public function filterPastTransactions($rootValue, array $args, GraphQLContext $context)
    {
        $query = DB::table('customer_payment');
        $today = date('Y-m-d');

        $query->select("customer_payment.*")->whereDate('customer_payment.created_at', '<', $today)->where('customer_id', '=', $args['id']);
        $query->orderBy('customer_payment.created_at', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }

    public function filterPendingTransactions($rootValue, array $args, GraphQLContext $context)
    {
        $today = date('Y-m-d');

        $query = \Webkul\Product\Models\Product::query();        
        $query->select('products.id as event_id', 'product_flat.name as event_name', 'products.created_at');
        $query->selectRaw('COALESCE(SUM(order_items.base_total), 0) as total_amount');
        $query->join('product_flat', 'products.id', '=', 'product_flat.product_id');
        $query->leftjoin('order_items', 'products.id', '=', 'order_items.product_id');
        $query->whereRaw('products.id NOT IN (select id from customer_payment where customer_id = '.$args['id'].')')->where('products.owner_id', '=', $args['id'])->where('products.type', '=', 'booking')->where('products.event_status', '!=', '2');
        $query->groupBy('products.id');
        $query->orderBy('products.id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }
}
