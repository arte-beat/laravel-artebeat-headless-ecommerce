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
use App\Models\BillingInfo;
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
        try {
            if (!empty($customer)) {
                $updatedData['customer_type'] = $args['input']['cutomerType']; // Event Manager =2 ,customer =1
                $updatedData['first_login'] = 1;
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
                throw new Exception('Unable to find Profile');
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

        $storePaymentMethod = [];
        $params = [];
        $createstripeCustomer = [];
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        $stripeCustomer = array();
        $createStripeUser = true;
        if (!empty($customer->stripe_customer_id)) {
            $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);
        }

        if (empty($stripeCustomer) && count($stripeCustomer) === 0) {
            $createStripeUser = false;
        }

        if($createStripeUser === false) {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['input']['stripeToken']
            ));

        }
        else{
            if(!empty($stripe_cust_id))
            {
                $validator = Validator::make($args['input'], [
                    'stripeToken' => 'required'
                ]);
                if ($validator->fails()) {
                    throw new Exception($validator->messages());
                }
                $retrieve_card_details_from_stripe =  Stripe\Customer::allSources($stripe_cust_id, ['object' => 'card']);
                $fingerprint_arr = array_map(function ($item) {
                    return $item['fingerprint'];
                }, $retrieve_card_details_from_stripe->data);
               if(!empty($args['input']['stripeToken']))
               {
                   $stripe_token_retrieve = Stripe\Token::retrieve($args['input']['stripeToken'],[]);
                   $curren_fingerprint = $stripe_token_retrieve->card['fingerprint'];
                   if(!in_array($curren_fingerprint,$fingerprint_arr))
                   {
                       $stripecardSave =  Stripe\Customer::createSource($stripe_cust_id, ['source' => $args['input']['stripeToken']]);

                       $params ['customer_id'] = $customer->id;
                       $params ['card_id'] = $stripe_token_retrieve->card['id'];
                       $params ['brand'] = $stripe_token_retrieve->card['brand'];
                       $params ['funding'] = $stripe_token_retrieve->card['funding'];
                       $params ['type'] = $stripe_token_retrieve->card['object'];
                       $params ['country'] = $stripe_token_retrieve->card['country'];
                       $params ['exp_month'] = $stripe_token_retrieve->card['exp_month'];
                       $params ['exp_year'] =$stripe_token_retrieve->card['exp_year'];
                       $params ['fingerprint'] = $stripe_token_retrieve->card['fingerprint'];
                       $params ['last4'] = $stripe_token_retrieve->card['last4'];
                       $params ['name'] = $stripe_token_retrieve->card['name'];
                       $params ['card_response'] = json_encode($stripe_token_retrieve);

                   }
                   else{
                       throw new Exception("We dont allow to store duplicate cards,please from already saved cards");
                   }

               }

            }
        }

        if (!empty($createstripeCustomer)) {
            $stripe_cust_id = $createstripeCustomer->id;
            $this->customerRepository->where('id', $customer->id)->update(['stripe_customer_id' => $stripe_cust_id]);
        }

        if(!empty($params))
        {
            try {
                $storePaymentMethod = $this->customerPaymentMethodsRepository->create($params);
                return $storePaymentMethod;
            } catch (\Exception $e) {
                throw new Exception($e->getMessage());
            }

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

        $storePaymentMethod = [];
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        $id = $args['id'];
        $cardDetails = $this->customerPaymentMethodsRepository->findOrFail($id);
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        $stripeCustomer = array();
        $createStripeUser = true;
        if (!empty($customer->stripe_customer_id)) {
            $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);
        }

        if (empty($stripeCustomer) && count($stripeCustomer) === 0) {
            $createStripeUser = false;
        }

          if($createStripeUser === false)  {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name,
                "source" => $args['input']['stripeToken']
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
        if($cardDetails)
        {
            $card_id = $cardDetails['card_id'];
            $update_card = Stripe\Customer::updateSource($stripe_cust_id, $card_id,["name" =>$args['input']['name'] ]);

            if (!empty($update_card)) {
                try {
                    $storePaymentMethod = $this->customerPaymentMethodsRepository->update($params, $id);
                    return $storePaymentMethod;
                } catch (\Exception $e) {
                    throw new Exception($e->getMessage());
                }
            }
        }

    }

    public function deleteCardDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $card_delete = [];
        $id = $args['id'];
        $cardExist = $this->customerPaymentMethodsRepository->findOrFail($id);
        Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        $customer = bagisto_graphql()->guard($this->guard)->user();
        $stripe_cust_id = $customer->stripe_customer_id;
        $card_id = $cardExist->card_id;
        $card_delete = Stripe\Customer::deleteSource($stripe_cust_id, $card_id, []);
        if ($card_delete['deleted']) {
            try {
                $this->customerPaymentMethodsRepository->delete($id);
                return ['success' => trans('admin::app.response.delete-success', ['name' => 'Card Details'])];
            } catch (\Exception $e) {
                throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Card Details']));
            }
        }
    }

    /*public function saveBankDetails($rootValue, array $args, GraphQLContext $context)
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

        $stripeCustomer = array();
        $createStripeUser = true;
        if (!empty($customer->stripe_customer_id)) {
            $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);
        }

        if (empty($stripeCustomer) && count($stripeCustomer) === 0) {
            $createStripeUser = false;
        }

          if($createStripeUser === false)  {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name
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
    }*/

    public function saveBankDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }
        $invoiceInfodata = [ 
            "customer_id" =>bagisto_graphql()->guard($this->guard)->user()->id,
            "country" => $args['input']['country'],
            "currency" => $args['input']['currency'],
            "account_holder_name" => $args['input']['account_holder_name'],
            "account_holder_type" => $args['input']['account_holder_type'],
            "routing_number" => $args['input']['routing_number'],
            "account_number" => $args['input']['account_number']
        ];
        $insertData = BillingInfo::updateOrCreate(
            ['customer_id' => bagisto_graphql()->guard($this->guard)->user()->id], 
            $invoiceInfodata
        );
        
        $returndata = [
            'id' => $insertData->id,
            'name' => $insertData->account_holder_name,
            'customer_id' => $insertData->customer_id,
            'country' => $insertData->country,
            'account_holder_type' => $insertData->account_holder_type,
            'currency' => $insertData->currency,
            'last4' => substr($insertData->account_number, -4),
            'routing_number' => $insertData->routing_number,
            'createdAt' => $insertData->created_at,
            'updatedAt' => $insertData->updated_at,
        ];
        
        $returndataVal = [
            'id' => $insertData->id,
            'name' => $insertData->account_holder_name,
            'customer_id' => $insertData->customer_id,
            'country' => $insertData->country,
            'account_holder_type' => $insertData->account_holder_type,
            'currency' => $insertData->currency,
            'last4' => substr($insertData->account_number, -4), // Corrected to extract last 4 characters
            'createdAt' => $insertData->created_at,
            'card_response' => json_encode($returndata), // Assuming this field requires JSON data
            'updatedAt' => $insertData->updated_at,
        ];
        return $returndataVal;
    }

    /*public function updateBankDetails($rootValue, array $args, GraphQLContext $context)
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

        $stripeCustomer = array();
        $createStripeUser = true;
        if (!empty($customer->stripe_customer_id)) {
            $stripeCustomer = Stripe\Customer::retrieve($stripe_cust_id);
        }

        if (empty($stripeCustomer) && count($stripeCustomer) === 0) {
            $createStripeUser = false;
        }

         {
            $createstripeCustomer = Stripe\Customer::create(array(
                "email" => $customer->email,
                "name" => $customer->first_name . ' ' . $customer->last_name
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
    }*/

    public function updateBankDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.invalid-header'));
        }

        if (!bagisto_graphql()->guard($this->guard)->check()) {
            throw new Exception(trans('bagisto_graphql::app.shop.customer.no-login-customer'));
        }
        $invoiceInfodata = [ 
            "account_holder_name" => $args['input']['account_holder_name'],
            "account_holder_type" => $args['input']['account_holder_type'],
        ];
        $insertData = BillingInfo::updateOrCreate(
            ['customer_id' => bagisto_graphql()->guard($this->guard)->user()->id], 
            $invoiceInfodata
        );
        
        $returndata = [
            'id' => $insertData->id,
            'name' => $insertData->account_holder_name,
            'customer_id' => $insertData->customer_id,
            'country' => $insertData->country,
            'account_holder_type' => $insertData->account_holder_type,
            'currency' => $insertData->currency,
            'last4' => substr($insertData->account_number, -4),
            'routing_number' => $insertData->routing_number,
            'createdAt' => $insertData->created_at,
            'updatedAt' => $insertData->updated_at,
        ];
        
        $returndataVal = [
            'id' => $insertData->id,
            'name' => $insertData->account_holder_name,
            'customer_id' => $insertData->customer_id,
            'country' => $insertData->country,
            'account_holder_type' => $insertData->account_holder_type,
            'currency' => $insertData->currency,
            'last4' => substr($insertData->account_number, -4), // Corrected to extract last 4 characters
            'createdAt' => $insertData->created_at,
            'card_response' => json_encode($returndata), // Assuming this field requires JSON data
            'updatedAt' => $insertData->updated_at,
        ];
        return $returndataVal;
    }

    /*public function deleteBankDetails($rootValue, array $args, GraphQLContext $context)
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
    }*/

    public function deleteBankDetails($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        // Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
        // $customer = bagisto_graphql()->guard($this->guard)->user();
        // $stripe_cust_id = $customer->stripe_customer_id;
        // $id = $args['id'];
        // $bankacc = $this->customerPaymentMethodsRepository->findOrFail($id);
        // $bank_account_id = $bankacc['card_id'];
        // $bank_account = Stripe\Customer::deleteSource($stripe_cust_id, $bank_account_id, []);

        // if ($bank_account['deleted']) {
        //     try {
        //         $this->customerPaymentMethodsRepository->delete($id);
        //         return ['success' => trans('admin::app.response.delete-success', ['name' => 'Bank Details'])];
        //     } catch (\Exception $e) {
        //         throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Bank Details']));
        //     }
        // }

        $invoice = BillingInfo::find($args['id']);
        if ($invoice) {
            try {
                $invoice->delete();
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

        foreach($result as $k=>$val){
            $getData = BillingInfo::where('customer_id',$customer->id)->first();
            $getData->last4 = substr($getData->account_number, -4);
            $result[$k]['paymenthistory'] = $getData;
            
        }

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
            $query = \Webkul\Sales\Models\Order::query();
            $query->addSelect("*");
            $query->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name");
            if (!empty($customer->email)) {
                $query->where('orders.customer_email', $customer->email);
            }
            $query->orderBy('orders.id', 'desc');
            $count = isset($args['first']) ? $args['first'] : 10;
            $page = isset($args['page']) ? $args['page'] : 1;
            $result = $query->paginate($count, ['*'], 'page', $page);

            if(!empty($result)) {
                foreach ($result as $index => $item) {
                    $cardDetails = [];
                    if(!empty($item['payment_method_id']))
                    {
                        $cardDetails = $this->customerPaymentMethodsRepository->findOrFail($item['payment_method_id']);
                    }
                    $result[$index]['mode_of_payment'] = '';
                    $result[$index]['funding'] = '';
                    $result[$index]['type'] = '';
                    $result[$index]['last4'] = '';
                    if (!empty($cardDetails)) {
                        $result[$index]['mode_of_payment'] = $cardDetails['funding'].' '. $cardDetails['type'];
                        $result[$index]['funding'] = $cardDetails['funding'];
                        $result[$index]['brand'] = $cardDetails['brand'];
                        $result[$index]['type'] = $cardDetails['type'];
                        $result[$index]['last4'] = $cardDetails['last4'];
                    }

                    $result[$index]['order_id'] = '#' . $item['id'];
                    $result[$index]['order_date'] = $item['created_at'];
                }
            }
        }
        return $result;
    }
}