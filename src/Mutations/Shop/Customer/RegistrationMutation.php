<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use Exception;
use JWTAuth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Webkul\Customer\Mail\RegistrationEmail;
use Webkul\Customer\Mail\VerificationEmail;
use Webkul\Customer\Http\Controllers\Controller;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Webkul\GraphQLAPI\Mail\SocialLoginPasswordResetEmail;
use Laravel\Socialite\Facades\Socialite;

use Webkul\SocialLogin\Models\CustomerSocialAccount;

class RegistrationMutation extends Controller
{
    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;

    /**
     * Contains error codes
     *
     * @var array
     */
    protected $errorCode = [
        'validation.unique',
        'validation.required',
        'validation.same'
    ];

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Customer\Repositories\CustomerRepository  $customerRepository
     * @param  \Webkul\Customer\Repositories\CustomerGroupRepository  $customerGroupRepository
     * @return void
     */
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected CustomerGroupRepository $customerGroupRepository
    )
    {
        $this->guard = 'api';

        auth()->setDefaultDriver($this->guard);
        
        $this->middleware('auth:' . $this->guard, ['except' => ['register']]);
    }

    /**
     * Method to store user's sign up form data to DB.
     *
     * @return \Illuminate\Http\Response
     */
    public function register($rootValue, array $args , GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.error-invalid-parameter'),
                'Invalid request parameters.'
            );
        }
    
        $data = $args['input'];
        
        $validator = Validator::make($data, [
            'first_name' => 'string|required',
            'last_name'  => 'string|required',
            'email'      => 'email|required|unique:customers,email',
            'password'   => 'min:6|required',
            'password_confirmation' => 'required|required_with:password|same:password',
        ]);
                
        if ($validator->fails()) {
            
            $errorMessage = [];
            foreach ($validator->messages()->toArray() as $index => $message) {
                $error = is_array($message) ? $message[0] : $message;
                
                $errorMessage[] = in_array($error, $this->errorCode) ? trans('bagisto_graphql::app.' . $error, ['field' => $index]) : $error;
            }
            
            throw new CustomException(
                implode(", ", $errorMessage),
                'Invalid Register Details.'
            );
        }

        $verificationData['email'] = $data['email'];
        $verificationData['token'] = md5(uniqid(rand(), true));

        $data = array_merge($data, [
            'password'      => bcrypt($data['password']),
            'api_token'     => Str::random(80),
            'is_verified'   => (int) core()->getConfigData('customer.settings.email.verification') ? 0 : 1,
//            'is_email_verified'   => 0,
            'subscribed_to_news_letter' => ! empty($data['subscribed_to_news_letter']) ? 1 : 0,
            'customer_group_id'         => $this->customerGroupRepository->findOneByField('code', 'general')->id,
            'token'      => $verificationData['token'],
        ]);

//        Event::dispatch('customer.registration.before');

        $customer = $this->customerRepository->create($data);

//        Event::dispatch('customer.registration.after', $customer);

        $encryptedKeyText = json_encode(["customerId" => $customer['id'], "email" => $customer['email']]);
        $verifyLink = config('otp.front_end_customer_register_mail_verify').Crypt::encryptString($encryptedKeyText);
        SendSignUpEvent::dispatch($customer, $data['email'],$verifyLink, 'customer');


        if (! $customer) {
            return [
                'status'    => false,
                'success'   => trans('bagisto_graphql::app.shop.response.error-registration')
            ]; 
        }

        if (core()->getConfigData('customer.settings.email.verification')) {
            
            try {
                $configKey = 'emails.general.notifications.emails.general.notifications.verification';
                
                if (core()->getConfigData($configKey)) {
                    Mail::queue(new VerificationEmail($verificationData));
                }
            } catch (Exception $e) {}

            return [
                'status'    => true,
                'success'   => trans('shop::app.customer.signup-form.success-verify')
            ];
        }
        
        try {
            $configCustomerKey = 'emails.general.notifications.emails.general.notifications.registration';

            if (core()->getConfigData($configCustomerKey)) {
                Mail::queue(new RegistrationEmail($data, 'customer'));
            }

            $configAdminKey = 'emails.general.notifications.emails.general.notifications.customer-registration-confirmation-mail-to-admin';

            if (core()->getConfigData($configAdminKey)) {
                Mail::queue(new RegistrationEmail(request()->all(), 'admin'));
            }
        } catch (Exception $e) {}

        $remember = !empty($data['remember']) ? 1 : 0;

        if (! $jwtToken = JWTAuth::attempt([
                'email'     => $data['email'],
                'password'  => $data['password_confirmation'],
            ], $remember)
        ) {
            throw new CustomException(
                trans('shop::app.customer.login-form.invalid-creds'),
                'Invalid Email and Password.'
            );
        }

        $loginCustomer = bagisto_graphql()->guard($this->guard)->user();

        if (
            $loginCustomer->status == 0
            || $loginCustomer->is_verified == 0
        ) {
            bagisto_graphql()->guard($this->guard)->logout();

            throw new CustomException(
                trans('shop::app.customer.login-form.not-activated'),
                'Account Not Activated.'
            );
        }

        return [
            'status'        => true,
            'success'       => trans('bagisto_graphql::app.shop.customer.success-login'),
            'access_token'  => 'Bearer ' . $jwtToken,
            'token_type'    => 'Bearer',
            'expires_in'    => bagisto_graphql()->guard($this->guard)->factory()->getTTL() * 60,
            'customer'      => $this->customerRepository->find($loginCustomer->id)
        ];
    }

    /**
     * Method to store user's sign up form data to DB.
     *
     * @return \Illuminate\Http\Response
     */
    public function socialSignUp($rootValue, array $args , GraphQLContext $context) {
        $data = $args;

        $validator = Validator::make($data, [
            'access_token' => 'string|required',
            'signup_type' => 'string|required|in:facebook,google,twitter,linkedin,github',
            'remember' => 'boolean',
        ]);
        
        $errorMessage = [];

        if ($validator->fails()) {
            foreach ($validator->messages()->toArray() as $index => $message) {
                $error = is_array($message) ? $message[0] : $message;
                $errorMessage[] = in_array($error, $this->errorCode) ? trans('bagisto_graphql::app.' . $error, ['field' => $index]) : $error;
            }
            
            throw new CustomException(
                implode(", ", $errorMessage),
                'Invalid Register Details.'
            );
        }

        $token = $data['access_token'];
        $signUpType = $data['signup_type'];

        $socialUser = Socialite::driver($signUpType)->userFromToken($token);

        if ($socialUser) {
            $socialLoginDetails = CustomerSocialAccount::where('provider_name', $signUpType)
                ->where('provider_id', $socialUser->id)
                ->first();
            
            if($socialLoginDetails && $socialLoginDetails->customer_id) {
                //Customer already exists
                $customer = $this->customerRepository->findOneByField('id', $socialLoginDetails->customer_id);

                return $this->loginCustomer($customer, $data);

            } else {
                // No Customer found
                if($socialUser->email){
                    $customer = $this->customerRepository->findOneByField('email', $socialUser->email);
                }
                // If customer email already exists Login the customer
                if (isset($customer) && $customer) {
                    if ($customer->status == 0 || $customer->is_verified == 0) {
                        throw new CustomException(trans('shop::app.customer.login-form.not-activated'),'Account Not Activated.');
                    }

                    CustomerSocialAccount::create([
                        'provider_name' => $signUpType,
                        'provider_id' => $socialUser->id,
                        'customer_id' => $customer->id,
                    ]);

                    return $this->loginCustomer($customer, $data);
                }

                //create customer
                $name = explode(" ",$socialUser->name);
                
                $data   = array_merge($data, [
                    'first_name'        => $name[0],
                    'last_name'         => isset($name[1]) ? $name[1] : '',
                    'email'             => isset($socialUser->email) ? $socialUser->email : null,
                    'api_token'         => Str::random(80),
                    'is_verified'       => core()->getConfigData('customer.settings.email.verification') ? 0 : 1,
                    'customer_group_id' => $this->customerGroupRepository->findOneWhere(['code' => 'general'])->id,
                    'token'             => $token,
                ]);

                Event::dispatch('customer.registration.before');

                $customer = $this->customerRepository->create($data);

                if (!$customer) {
                    return [
                        'status'    => false,
                        'success'   => trans('bagisto_graphql::app.shop.response.error-registration')
                    ]; 
                }

                CustomerSocialAccount::create([
                    'provider_name' => $signUpType,
                    'provider_id' => $socialUser->id,
                    'customer_id' => $customer->id,
                ]);

                try {
                    $configCustomerKey = 'emails.general.notifications.emails.general.notifications.registration';
                    if (core()->getConfigData($configCustomerKey)) {
                        $data['is_social_login'] = true;
                        Mail::queue(new RegistrationEmail($data, 'customer'));
                    }

                    $configAdminKey = 'emails.general.notifications.emails.general.notifications.customer-registration-confirmation-mail-to-admin';
                    if (core()->getConfigData($configAdminKey)) {
                        Mail::queue(new RegistrationEmail($data, 'admin'));
                    }
                } catch (Exception $e) {}

                $customer = $this->customerRepository->findOneByField('email', $socialUser->email);

                return $this->loginCustomer($customer, $data);
            }
        } else {
            //No Social User Found
            return new Exception("Invalid token");
        }
    }

    /**
     * Login the customer using socialSignUp API.
     *
     * @param  \Webkul\Customer\Repositories\CustomerRepository  $customer
     * @param  array  $data
     * @return \Illuminate\Http\Response
     */
    public function loginCustomer($customer, $data)
    {
        $jwtToken = null;
        
        if (!isset($data['password'])) {
            $data['password'] = $password = Str::random(8);
            
            $customer->password = bcrypt($password);
            $customer->save();
            
            try {
                Mail::queue(new SocialLoginPasswordResetEmail($data));
            } catch(\Exception $e) {}
        }
        
        $remember = isset($data['remember']) ? $data['remember'] : 0;

        $jwtToken = JWTAuth::attempt([
            'email'     => $customer->email,
            'password'  => $data['password'],
        ], $remember);

        if (!$jwtToken) {
            throw new CustomException(
                trans('shop::app.customer.login-form.invalid-creds'),
                'Invalid Email and Password.'
            );
        }
        
        $customer = bagisto_graphql()->guard($this->guard)->user();

        /**
         * Event passed to prepare cart after login.
         */
        // Event::dispatch('customer.after.login', $customer->email);

        return [
            'status'        => true,
            'success'       => trans('bagisto_graphql::app.shop.customer.success-login'),
            'access_token'  => 'Bearer ' . $jwtToken,
            'token_type'    => 'Bearer',
            'expires_in'    => bagisto_graphql()->guard($this->guard)->factory()->getTTL() * 60,
            'customer'      => $this->customerRepository->find($customer->id)
        ];
    }

    public function verifyCustomerLogin($rootValue, array $args , GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'encryptedKey' => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $decryptedKey = Crypt::decryptString($data['encryptedKey']);
        $decryptedKeyArr = json_decode($decryptedKey);


        $customer = $this->customerRepository->findOneByField('email', $decryptedKeyArr->email);

        if($decryptedKeyArr->email != $customer['email']){
            throw new Exception(config('exceptionmessages.invalid_email'));
        }
        if(!empty($customer))
        {
            if($customer['email'] == $decryptedKeyArr->email && $customer['id'] == $decryptedKeyArr->customerId) {
                $customer::whereId($decryptedKeyArr->customerId)->update( ['is_verified' => 1]);
            }else{
                throw new Exception('We are unable to find account with given email & user id');
            }
        }

        return [
            'status'    => 'success',
            'success'   => 'Login verified successfully.',
            'customerId'   => $customer['id'],
            'email'   => $customer['email'],
        ];
    }
}