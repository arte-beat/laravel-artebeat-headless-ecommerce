<?php

namespace Webkul\GraphQLAPI\Mutations\Setting;

use Exception;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Password;
use JWTAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Http\Controllers\Controller;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Webkul\User\Repositories\RoleRepository;
use Webkul\User\Repositories\AdminRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Helpers\OTPGenerationHelper;
use App\Events\SendOTPEvent;


class UserMutation extends Controller
{
    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\User\Repositories\AdminRepository  $adminRepository
     * @param  \Webkul\User\Repositories\RoleRepository  $roleRepository
     * @return void
     */
    public function __construct(
       protected AdminRepository $adminRepository,
       protected RoleRepository $roleRepository
    ) {
        $this->guard = 'admin-api';

        auth()->setDefaultDriver($this->guard);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        if($data['role_id'] == env('SUPER_ADMIN')){
            throw new Exception('{"role_id":["You are not allowed to create super admin."]}');
        }

        $validator = Validator::make($data, [
            'name'     => 'required',
            'email'    => 'email|unique:admins,email',
            'password' => 'nullable',
            'password_confirmation' => 'nullable|required_with:password|same:password',
            'status'   => 'sometimes',
            'role_id'  => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            if (isset($data['password']) && $data['password']) {
                $data['password'] = bcrypt($data['password']);
                $data['api_token'] = Str::random(80);
            }

            Event::dispatch('user.admin.create.before');

            $admin = $this->adminRepository->create($data);

            Event::dispatch('user.admin.create.after', $admin);

            return $admin;
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
        if (!isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];
        if($id == env('SUPER_ADMIN')){
            throw new Exception('{"role_id":["You are not allowed to update super admin."]}');
        }
        if($data['role_id'] == env('SUPER_ADMIN')){
            throw new Exception('{"role_id":["You are not allowed to update super admin in another profile."]}');
        }

        $validator = Validator::make($data, [
            'name'     => 'required',
            'email'    => 'email|unique:admins,email,' . $id,
            'password' => 'nullable',
            'password_confirmation' => 'nullable|required_with:password|same:password',
            'status'   => 'sometimes',
            'role_id'  => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            if (!$data['password']) {
                unset($data['password']);
            } else {
                $isPasswordChanged = true;
                $data['password'] = bcrypt($data['password']);
            }

            if (isset($data['status'])) {
                $data['status'] = 1;
            } else {
                $data['status'] = 0;
            }

            Event::dispatch('user.admin.update.before', $id);

            $admin = $this->adminRepository->update($data, $id);

            if ($isPasswordChanged) {
                Event::dispatch('user.admin.update-password', $admin);
            }

            Event::dispatch('user.admin.update.after', $admin);

            return $admin;
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
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        if($id == env('SUPER_ADMIN')){
            throw new Exception('{"id":["You are not allowed to delete super admin."]}');
        }
        $user = $this->adminRepository->findOrFail($id);

        if ($this->adminRepository->count() == 1) {
            throw new Exception(trans('admin::app.response.last-delete-error', ['name' => 'Admin']));
        } else {
            try {
                Event::dispatch('user.admin.delete.before', $id);

                $this->adminRepository->delete($id);

                Event::dispatch('user.admin.delete.after', $id);

                return ['success' => trans('admin::app.response.delete-success', ['name' => 'Admin'])];
            } catch (\Exception $e) {
                throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Admin']));
            }
        }
    }

    /**
     * Login user resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'email'     => 'required|email',
            'password'  => 'required',
            'role_id'  => 'required|regex:/^[0-9]+$/u',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $remember = isset($data['remember']) ? $data['remember'] : 0;

        if (!$jwtToken = JWTAuth::attempt([
            'email'     => $data['email'],
            'password'  => $data['password'],
            'role_id'  => $data['role_id'],
        ], $remember)) {
            throw new Exception(trans('admin::app.users.users.login-error'));
        }

        try {
            if (bagisto_graphql()->guard($this->guard)->user()->status == 0) {
                bagisto_graphql()->guard($this->guard)->logout();

                return [
                    'status'    => false,
                    'success'   => trans('admin::app.users.users.activate-warning'),
                ];
            }

            return [
                'status'        => true,
                'success'       => trans('bagisto_graphql::app.admin.response.success-login'),
                'access_token'  => 'Bearer ' . $jwtToken,
                'token_type'    => 'Bearer',
                'expires_in'    => bagisto_graphql()->guard($this->guard)->factory()->getTTL() * 60,
                'user'          => bagisto_graphql()->guard($this->guard)->user()
            ];
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function logout()
    {
        if (auth()->guard($this->guard)->check()) {
            auth()->guard($this->guard)->logout();

            return [
                'status'    => true,
                'success'   => trans('bagisto_graphql::app.admin.response.success-logout'),
            ];
        }

        return [
            'status'    => false,
            'success'   => trans('bagisto_graphql::app.admin.response.no-login-user'),
        ];
    }

    /**
     * Method to reset the user password
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
            $Admin = bagisto_graphql()->guard($this->guard)->user();
            $admin = $Admin::where("email", "=", $data['email'])->first();
            if(!$admin){
                throw new Exception('{"email":["We are unable to find account with given email. Please try again."]}');
            }

            $OTP = (new OTPGenerationHelper())->generateNumericOTP(config('otp.generate_otp_number'));
            $encryptedKeyText = json_encode(["email" => $data['email'], "otp" => $OTP]);
            $verifyLink = config('otp.front_end_url').Crypt::encryptString($encryptedKeyText);
//            dd($verifyLink);
            SendOTPEvent::dispatch($data['email'], $OTP, $verifyLink);

            dd(1111);

//            $response = $this->broker()->sendResetLink($data);
//
//            if ($response == Password::RESET_LINK_SENT) {
//                return [
//                    'status'    => true,
//                    'success'   => trans('customer::app.forget_password.reset_link_sent')
//                ];
//            } else {
//                throw new CustomException(
//                    trans('bagisto_graphql::app.shop.response.password-reset-failed'),
//                    'Invalid ForgotPassword Email Details.'
//                );
//            }
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
}
