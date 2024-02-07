<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Webkul\Customer\Http\Controllers\Controller;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Webkul\Customer\Models\Customer;
use App\Exceptions\ExpiredOTPException;
use App\Exceptions\InvalidOTPException;
use App\Models\UserOTP;
use App\Helpers\OTPGenerationHelper;
use App\Events\SendOTPEvent;

class ForgotPasswordMutation extends Controller
{
    use SendsPasswordResetEmails;

    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->guard = 'api';

        auth()->setDefaultDriver($this->guard);
        
        $this->middleware('auth:' . $this->guard, ['except' => ['forgot']]);
    }

    /**
     * Method to reset the customer password
     *
     * @return \Illuminate\Http\Response
     */
    public function forgot($rootValue, array $args , GraphQLContext $context)
    {

        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
    
        $data = $args['input'];
        
        $validator = Validator::make($data, [
            'email' => 'required|email',
        ]);
                
        if ($validator->fails()) {
            $errorMessage = [];
            foreach ($validator->messages()->toArray() as $message) {
                $errorMessage[] = is_array($message) ? $message[0] : $message;
            }
            
            throw new CustomException(
                implode(" ,", $errorMessage),
                'Invalid ForgotPassword Details.'
            );
        }
        
        try {
            $customer = Customer::where("email", "=", $data['email'])->first();
            if(!$customer){
                throw new Exception('We are unable to find account with given email. Please try again.');
            }
            else{
                if(!empty($customer->is_social_login)){
                    throw new Exception('We are unable to Process as this account has LoggedIn through Social Login.');
                }

            }

            $OTP = (new OTPGenerationHelper())->generateNumericOTP(config('otp.generate_otp_number'));
            $encryptedKeyText = json_encode(["customerId" => $customer['id'], "otp" => $OTP]);
            $verifyLink = config('otp.front_end_customer_url').Crypt::encryptString($encryptedKeyText);
            $userOTP = [
                'user_id' => $customer['id'],
                'user_type' => config('otp.customer_user_type'),
                'expire_at' => Carbon::now()->addMinute(config('otp.expire_time_in_minutes')),
                'otp' => $OTP,
                'verify_link' => $verifyLink
            ];

            (new UserOTP())->create(
                $userOTP
            );
            SendOTPEvent::dispatch($customer, $data['email'], $OTP, $verifyLink, 'customer');

            return [
                'status'    => true,
                'success'   => trans('customer::app.forget_password.reset_link_sent')
            ];
        } catch (\Swift_RfcComplianceException $e) {
            throw new CustomException(
                trans('customer::app.forget_password.reset_link_sent'),
                'Swift_RfcComplianceException: Invalid ForgotPassword Details.'
            );
        } catch (Exception $e) {
            throw new CustomException(
                $e->getMessage(),
                'Exception: invalid forgot password email.'
            );
        }
    }

    /**
     * Method to reset the user password
     *
     * @return \Illuminate\Http\Response
     */
    public function verifyForgotPasswordOTP($rootValue, array $args , GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'otp' => 'required|numeric',
            'encryptedKey' => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $decryptedKey = Crypt::decryptString($data['encryptedKey']);
        $decryptedKeyArr = json_decode($decryptedKey);

        if($decryptedKeyArr->otp != $data['otp']){
            throw new Exception(config('exceptionmessages.invalid_otp'));
        }
        $admin = UserOTP::where(UserOTP::USER_ID, '=', $decryptedKeyArr->customerId)
            ->take(1)
            ->orderBy(UserOTP::ID, 'desc')
            ->first();

        if(empty($admin->{UserOTP::EXPIRE_AT})){
            throw new Exception(config('exceptionmessages.invalid_otp'));
        }
        $totalDuration = Carbon::now()->diffInMinutes(Carbon::parse($admin->{UserOTP::EXPIRE_AT}), false);
        if($totalDuration < 1){
            throw new Exception(config('exceptionmessages.otp_expired'));
        }

        $customer = Customer::where("id", "=", $admin->{UserOTP::USER_ID})->first();
        return [
            'status'    => 'success',
            'success'   => 'Email verified successfully.',
            'customerId'   => $decryptedKeyArr->customerId,
            'email'   => $customer->email,
        ];
    }

    /**
     * Method to reset the user password
     *
     * @return \Illuminate\Http\Response
     */
    public function resetPassword($rootValue, array $args , GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'newPassword' => 'required',
            'confirmPassword' => 'required|required_with:newPassword|same:newPassword',
            'customerId' => 'required',
            'email'     => 'required|email',
            'otp' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $admin = UserOTP::where(UserOTP::USER_ID, '=', $data['customerId'])
            ->take(1)
            ->orderBy(UserOTP::ID, 'desc')
            ->first();

        if(empty($admin)){
            throw new Exception('We are unable to find account with given user id');
        }

        if($admin->otp != $data['otp']){
            throw new Exception(config('exceptionmessages.invalid_otp'));
        }

        $customer = Customer::where("email", "=", $data['email'])->first();
        if(empty($customer)){
            throw new Exception('We are unable to find account with given email');
        }
        if($customer->email == $data['email'] && $admin->otp == $data['otp']) {
            $customer::whereId($data['customerId'])->update([
                'password' => Hash::make($data['newPassword'])
            ]);
        }else{
            throw new Exception('We are unable to find account with given email & user id');
        }

        return [
            'status'    => 'success',
            'success'   => 'Password changed successfully.',
        ];
    }

    /**
     * Get the broker to be used during password reset.
     *
     * @return \Illuminate\Contracts\Auth\PasswordBroker
     */
    public function broker()
    {
        return Password::broker('customers');
    }
}