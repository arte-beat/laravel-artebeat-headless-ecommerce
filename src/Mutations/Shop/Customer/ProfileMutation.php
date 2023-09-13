<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use Exception;
use Hash;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Event;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Customer\Http\Controllers\Controller;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Customer\Repositories\CustomerPaymentMethodsRepository;
use \Webkul\Customer\Models\CustomerPaymentMethods;
use \Webkul\Customer\Models\Customer;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Stripe;

class ProfileMutation extends Controller
{
    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;

    /**
     * CustomerRepository object
     *
     * @var \Webkul\Customer\Repositories\CustomerRepository
     */
    protected $customerRepository, $customerPaymentMethodsRepository;

    /**
     * allowedImageMimeTypes array
     *
     */
    protected $allowedImageMimeTypes = [
        'png' => 'image/png',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
    ];

    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Customer\Repositories\CustomerRepository $customerRepository
     * @param \Webkul\Customer\Repositories\CustomerPaymentMethodsRepository $customerPaymentMethodsRepository
     * @return void
     */
    public function __construct(
        CustomerRepository               $customerRepository,
        CustomerPaymentMethodsRepository $customerPaymentMethodsRepository
    )
    {
        $this->guard = 'api';

        auth()->setDefaultDriver($this->guard);

        $this->middleware('auth:' . $this->guard);

        $this->customerRepository = $customerRepository;
        $this->customerPaymentMethodsRepository = $customerPaymentMethodsRepository;
    }

    /**
     * Returns a current customer data.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function get($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.invalid-header'),
                'Invalid request header parameter.'
            );
        }

        if (bagisto_graphql()->guard($this->guard)->check()) {

            $customer = bagisto_graphql()->guard($this->guard)->user();

            return [
                'status' => $customer ? true : false,
                'customer' => $customer,
                'message' => trans('bagisto_graphql::app.shop.response.customer-details')
            ];
        } else {
            return [
                'status' => false,
                'customer' => null,
                'message' => trans('bagisto_graphql::app.shop.customer.no-login-customer')
            ];
        }
    }


    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.invalid-header'),
                'Invalid request header parameter.'
            );
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new CustomException(
                trans('bagisto_graphql::app.shop.customer.no-login-customer'),
                'No Login Customer Found.'
            );
        }

        $customer = bagisto_graphql()->guard($this->guard)->user();

        $data = $args['input'];

        $isPasswordChanged = false;

        if (count($data) == 0) {
            throw new CustomException('Nothing to update', 'Invalid Update Profile Details.');
        }

        try {
            if (
                isset ($data['date_of_birth'])
                && $data['date_of_birth'] == ""
            ) {
                unset($data['date_of_birth']);
            }

            $data['date_of_birth'] = (isset($data['date_of_birth']) && $data['date_of_birth']) ? Carbon::createFromTimeString(str_replace('/', '-', $data['date_of_birth']) . '00:00:01')->format('Y-m-d') : '';

            if (isset ($data['oldpassword'])) {
                if ($data['oldpassword'] != "" || $data['oldpassword'] != null) {

                    if (Hash::check($data['oldpassword'], $customer->password)) {
                        $isPasswordChanged = true;
                        $data['password'] = bcrypt($data['password']);
                    } else {
                        throw new CustomException(
                            trans('shop::app.customer.account.profile.unmatch'),
                            'Wrong Customer Password.'
                        );
                    }
                } else {
                    unset($data['password']);
                }
            }

            Event::dispatch('customer.update.before');

            if ($customer = $this->customerRepository->update($data, $customer->id)) {

                if ($isPasswordChanged) {
                    Event::dispatch('user.admin.update-password', $customer);
                }

                Event::dispatch('customer.update.after', $customer);

                if (
                    core()->getCurrentChannel()->theme != 'default'
                    && !empty($data['upload_type'])
                ) {

                    if ($data['upload_type'] == 'file') {

                        if (!empty($data['image'])) {
                            $customer->image = $data['image']->storePublicly('customer/' . $customer->id);
                            $customer->save();
                        } else {

                            if ($customer->image) {
                                Storage::delete($customer->image);
                            }

                            $customer->image = null;
                            $customer->save();
                        }
                    }

                    if (
                        in_array($data['upload_type'], ['path', 'base64'])
                        && !empty($data['image_url'])
                    ) {
                        $data['save_path'] = 'customer/' . $customer->id;

                        bagisto_graphql()->saveImageByURL($customer, $data, 'image_url');
                    }
                }

                return [
                    'status' => $customer ? true : false,
                    'customer' => $customer,
                    'message' => trans('shop::app.customer.account.profile.edit-success')
                ];
            } else {
                throw new CustomException(
                    trans('shop::app.customer.account.profile.edit-fail'),
                    'Customer Profile Update Failed.'
                );
            }
        } catch (Exception $e) {
            throw new CustomException(
                $e->getMessage(),
                'Customer Update Failed.'
            );
        }
    }

    /**
     * Change Customer Type
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function changeCustomerType($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }
        $data = $args['input'];

        $customer = bagisto_graphql()->guard($this->guard)->user();
//        dd($customer);
        try {
            if (Hash::check($data['password'], $customer->password)) {
                $updatedData['customer_type'] = 2; // Event Manager
                if ($customer = $this->customerRepository->update($updatedData, $customer->id)) {
                    return [
                        'status' => true,
                        'success' => trans('shop::app.customer.account.profile.edit-success', ['name' => 'Customer'])
                    ];
                } else {
                    throw new CustomException(
                        trans('shop::app.customer.account.profile.edit-fail'),
                        'Customer Type Change Failed.'
                    );
                }
            } else {
                throw new Exception(trans('shop::app.customer.account.address.delete.wrong-password'));
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }
        $data = $args['input'];

        $customer = bagisto_graphql()->guard($this->guard)->user();

        try {
            if (Hash::check($data['password'], $customer->password)) {
                $orders = $customer->all_orders->whereIn('status', ['pending', 'processing'])->first();

                if ($orders) {
                    throw new Exception(trans('admin::app.response.order-pending', ['name' => 'Customer']));
                } else {
                    $this->customerRepository->delete($customer->id);

                    return [
                        'status' => true,
                        'success' => trans('admin::app.response.delete-success', ['name' => 'Customer'])
                    ];
                }
            } else {
                throw new Exception(trans('shop::app.customer.account.address.delete.wrong-password'));
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function saveCardDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }

        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        if (empty($customer->stripe_customer_id)) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }

        $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);

        if (count($stripeCustomer) === 0) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }
        if (!empty($createstripeCustomer)) {
            $stripe_cust_id = $createstripeCustomer->id;
            $this->customerRepository->where('id', $customer->id)->update(['stripe_customer_id' => $stripe_cust_id]);
        }
        $params ['customer_id'] = $customer->id;
        $params ['card_id'] = $args['input']['card'][0]['id'];
        $params ['brand'] = $args['input']['card'][0]['brand'];
        $params ['funding'] = $args['input']['card'][0]['funding'];
        $params ['type'] = $args['input']['card'][0]['object'];
        $params ['country'] = $args['input']['card'][0]['country'];
        $params ['exp_month'] = $args['input']['card'][0]['exp_month'];
        $params ['exp_year'] = $args['input']['card'][0]['exp_year'];
        $params ['last4'] = $args['input']['card'][0]['last4'];
        $params ['name'] = $args['input']['card'][0]['name'];
        $params ['card_response'] = json_encode($args['input']);
        $validator = Validator::make($args['input'], [
            'stripeToken' => 'required',
            'card' => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }
        try {
            $storePaymentMethod = $this->customerPaymentMethodsRepository->create($params);
            return $storePaymentMethod;
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }

    }

    public function updateCardDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }

        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        if (empty($customer->stripe_customer_id)) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }

        $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);

        if (count($stripeCustomer) === 0) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }
        if (!empty($createstripeCustomer)) {
            $stripe_cust_id = $createstripeCustomer->id;
            $this->customerRepository->where('id', $customer->id)->update(['stripe_customer_id' => $stripe_cust_id]);
        }

        $params ['name'] = $args['input']['name'];
        $id = $args['id'];
        $validator = Validator::make($args['input'], [
            'name' => 'required'
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }
        try {
            $storePaymentMethod = $this->customerPaymentMethodsRepository->update($params, $id);
            return $storePaymentMethod;
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }

    }

    public function deleteCardDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $cardExist = $this->customerPaymentMethodsRepository->findOrFail($id);

        try {
            $this->customerPaymentMethodsRepository->delete($id);
            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Card Details'])];
        } catch (\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Card Details']));
        }

    }

    public function saveBankDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }
        $storePaymentMethod = [];
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        if (empty($customer->stripe_customer_id)) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }

        $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);

        if (count($stripeCustomer) === 0) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }
        if (!empty($createstripeCustomer)) {
            $stripe_cust_id = $createstripeCustomer->id;
            $this->customerRepository->where('id', $customer->id)->update(['stripe_customer_id' => $stripe_cust_id]);
        }
        $bank_account = Stripe\Customer::createSource($stripe_cust_id, [
            "source" => array(
                "object" => "bank_account",
                "country" => $args['input']['country'],
                "currency" => $args['input']['currency'],
                "account_holder_name" => $args['input']['account_holder_name'],
                "account_holder_type" => $args['input']['account_holder_type'],
                "routing_number" => $args['input']['routing_number'],
                "account_number" => $args['input']['account_number']
            )
        ]);

        if ($bank_account) {
            $params ['customer_id'] = $customer->id;
            $params ['card_id'] = $bank_account['id'];
            $params ['type'] = $bank_account['object'];
            $params ['country'] = $bank_account['country'];
            $params ['last4'] = $bank_account['last4'];
            $params ['name'] = $bank_account['account_holder_name'];
            $params ['fingerprint'] = $bank_account['fingerprint'];
            $params ['account_holder_type'] = $bank_account['account_holder_type'];
            $params ['account_type'] = $bank_account['account_type'];
            $params ['bank_name'] = $bank_account['bank_name'];
            $params ['currency'] = $bank_account['currency'];
            $params ['card_response'] = json_encode($bank_account);
            $storePaymentMethod = $this->customerPaymentMethodsRepository->create($params);

        }
        return $storePaymentMethod;
    }

    public function updateBankDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }
        $storePaymentMethod = [];
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        if (empty($customer->stripe_customer_id)) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }

        $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);

        if (count($stripeCustomer) === 0) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['stripeToken']
            ));
        }
        if (!empty($createstripeCustomer)) {
            $stripe_cust_id = $createstripeCustomer->id;
            $this->customerRepository->where('id', $customer->id)->update(['stripe_customer_id' => $stripe_cust_id]);
        }


        $id = $args['id'];
        $bankacc = $this->customerPaymentMethodsRepository->findOrFail($id);
        $bank_account_id = $bankacc['card_id'];
        $bank_account = Stripe\Customer::updateSource($stripe_cust_id, $bank_account_id,
            [
                "account_holder_name" => $args['input']['account_holder_name'],
                "account_holder_type" => $args['input']['account_holder_type']
            ]);

        if (!empty($bank_account)) {
            try {
                $params ['name'] = $bank_account['account_holder_name'];
                $params ['account_holder_type'] = $bank_account['account_holder_type'];
                $params ['account_type'] = $bank_account['account_type'];
                $params ['card_response'] = json_encode($bank_account);
                $storePaymentMethod = $this->customerPaymentMethodsRepository->update($params, $id);

            } catch (\Exception $e) {
                throw new Exception($e->getMessage());
            }
        }

        return $storePaymentMethod;
    }

    public function deleteBankDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        $id = $args['id'];
        $bankacc = $this->customerPaymentMethodsRepository->findOrFail($id);
        $bank_account_id = $bankacc['card_id'];
        $bank_account = Stripe\Customer::deleteSource($stripe_cust_id, $bank_account_id, []);

        if ($bank_account['deleted']) {
            try {
                $this->customerPaymentMethodsRepository->delete($id);
                return ['success' => trans('admin::app.response.delete-success', ['name' => 'Bank Details'])];
            } catch (\Exception $e) {
                throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Bank Details']));
            }
        }
    }

    public function getOrganizerProfileDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.invalid-header'),
                'Invalid request header parameter.'
            );
        }
        $customer = bagisto_graphql()->guard($this->guard)->user();

        $query = \Webkul\Customer\Models\Customer::query();
        $owner = bagisto_graphql()->guard($this->guard)->user();
        if (!empty($customer)) {
            $query->where('id', $customer->id);
        }

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $result = $query->paginate($count, ['*'], 'page', $page);

        return $result;

    }

      public function getAllPaymentsHistory($rootValue, array $args, GraphQLContext $context)
    {

        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }
        $customer = bagisto_graphql()->guard($this->guard)->user();
        if (!empty($customer)) {
            DB::enableQueryLog();
            $query = \Webkul\Sales\Models\Order::query();
            $query->addSelect("*");
            $query->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name");
            if (!empty($customer->email)) {
                $query->where('orders.customer_email', $customer->email);
            }
            $query->orderBy('orders.id', 'desc');
            // dd(DB::getQueryLog());
            $count = isset($args['first']) ? $args['first'] : 10;
            $page = isset($args['page']) ? $args['page'] : 1;
            $result = $query->paginate($count, ['*'], 'page', $page);
            foreach ($result as $index => $item) {
                $result[$index]['mode_of_payment'] = 'Credit Card';
                $result[$index]['order_id'] = '#' . $item['id'];
            }
        }
        return $result;
    }
}