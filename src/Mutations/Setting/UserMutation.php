<?php

namespace Webkul\GraphQLAPI\Mutations\Setting;

use App\Exceptions\ExpiredOTPException;
use App\Exceptions\InvalidOTPException;
use App\Models\Admin;
use App\Models\UserOTP;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
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
use Illuminate\Support\Facades\Storage;



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
     * @param \Webkul\User\Repositories\AdminRepository $adminRepository
     * @param \Webkul\User\Repositories\RoleRepository $roleRepository
     * @return void
     */
    public function __construct(
        protected AdminRepository $adminRepository,
        protected RoleRepository  $roleRepository
    )
    {
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
        if ($data['role_id'] == env('SUPER_ADMIN')) {
            throw new Exception('{"role_id":["You are not allowed to create super admin."]}');
        }

        $validator = Validator::make($data, [
            'name' => 'required',
            'email' => 'email|unique:admins,email',
            'password' => 'nullable',
            'password_confirmation' => 'nullable|required_with:password|same:password',
            'status' => 'sometimes',
            'role_id' => 'required',
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
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];
        if ($id == env('SUPER_ADMIN')) {
            throw new Exception('{"role_id":["You are not allowed to update super admin."]}');
        }
        if ($data['role_id'] == env('SUPER_ADMIN')) {
            throw new Exception('{"role_id":["You are not allowed to update super admin in another profile."]}');
        }

        $validator = Validator::make($data, [
            'name' => 'required',
            'password' => 'nullable',
            'password_confirmation' => 'nullable|required_with:password|same:password',
            'status' => 'sometimes',
            'role_id' => 'required',
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
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        if ($id == env('SUPER_ADMIN')) {
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
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $remember = isset($data['remember']) ? $data['remember'] : 0;

        if (!$jwtToken = JWTAuth::attempt([
            'email' => $data['email'],
            'password' => $data['password'],
        ], $remember)) {
            throw new Exception(trans('admin::app.users.users.login-error'));
        }

        try {
            if (bagisto_graphql()->guard($this->guard)->user()->status == 0) {
                bagisto_graphql()->guard($this->guard)->logout();

                return [
                    'status' => false,
                    'success' => trans('admin::app.users.users.activate-warning'),
                ];
            }

            return [
                'status' => true,
                'success' => trans('bagisto_graphql::app.admin.response.success-login'),
                'access_token' => 'Bearer ' . $jwtToken,
                'token_type' => 'Bearer',
                'expires_in' => bagisto_graphql()->guard($this->guard)->factory()->getTTL() * 60,
                'user' => bagisto_graphql()->guard($this->guard)->user()
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
                'status' => true,
                'success' => trans('bagisto_graphql::app.admin.response.success-logout'),
            ];
        }

        return [
            'status' => false,
            'success' => trans('bagisto_graphql::app.admin.response.no-login-user'),
        ];
    }

    /**
     * Method to reset the user password
     *
     * @return \Illuminate\Http\Response
     */
    public function forgot($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $admin = Admin::where("email", "=", $data['email'])->first();
            if (!$admin) {
                throw new Exception('We are unable to find account with given email. Please try again.');
            }

            $OTP = (new OTPGenerationHelper())->generateNumericOTP(config('otp.generate_otp_number'));
            $encryptedKeyText = json_encode(["userId" => $admin['id'], "otp" => $OTP]);
            $verifyLink = config('otp.front_end_url') . Crypt::encryptString($encryptedKeyText);
            $userOTP = [
                'user_id' => $admin['id'],
                'user_type' => config('otp.admin_user_type'),
                'expire_at' => Carbon::now()->addMinute(config('otp.expire_time_in_minutes')),
                'otp' => $OTP,
                'verify_link' => $verifyLink
            ];

            (new UserOTP())->create(
                $userOTP
            );
            SendOTPEvent::dispatch($admin, $data['email'], $OTP, $verifyLink, 'admin');

            return [
                'status' => true,
                'success' => trans('customer::app.forget_password.reset_link_sent')
            ];
        } catch (\Swift_RfcComplianceException $e) {
            throw new Exception(trans('customer::app.forget_password.reset_link_sent'));
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Method to reset the user password
     *
     * @return \Illuminate\Http\Response
     */
    public function verifyForgotPasswordOTP($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
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

        if ($decryptedKeyArr->otp != $data['otp']) {
            throw new Exception(config('exceptionmessages.invalid_otp'));
        }
        $admin = UserOTP::where(UserOTP::USER_ID, '=', $decryptedKeyArr->userId)
            ->take(1)
            ->orderBy(UserOTP::ID, 'desc')
            ->first();

        if (empty($admin->{UserOTP::EXPIRE_AT})) {
            throw new Exception(config('exceptionmessages.invalid_otp'));
        }
        $totalDuration = Carbon::now()->diffInMinutes(Carbon::parse($admin->{UserOTP::EXPIRE_AT}), false);
        if ($totalDuration < 1) {
            throw new Exception(config('exceptionmessages.otp_expired'));
        }

        $admin = Admin::where("id", "=", $admin->{UserOTP::USER_ID})->first();
        return [
            'status' => 'success',
            'success' => 'Email verified successfully.',
            'userId' => $decryptedKeyArr->userId,
            'email' => $admin->email,
        ];
    }

    /**
     * Method to reset the user password
     *
     * @return \Illuminate\Http\Response
     */
    public function resetPassword($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'newPassword' => 'required',
            'confirmPassword' => 'required|required_with:newPassword|same:newPassword',
            'userId' => 'required',
            'email' => 'required|email',
            'otp' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $admin = UserOTP::where(UserOTP::USER_ID, '=', $data['userId'])
            ->take(1)
            ->orderBy(UserOTP::ID, 'desc')
            ->first();

        if (empty($admin)) {
            throw new Exception('We are unable to find account with given user id');
        }

        if ($admin->otp != $data['otp']) {
            throw new Exception(config('exceptionmessages.invalid_otp'));
        }

        $user = Admin::where("email", "=", $data['email'])->first();
        if (empty($user)) {
            throw new Exception('We are unable to find account with given email');
        }
        if ($user->email == $data['email'] && $admin->otp == $data['otp']) {
            $user::whereId($data['userId'])->update([
                'password' => Hash::make($data['newPassword'])
            ]);
        } else {
            throw new Exception('We are unable to find account with given email & user id');
        }

        return [
            'status' => 'success',
            'success' => 'Password changed successfully.',
        ];
    }


    public function getAdminProfile($rootValue, array $args, GraphQLContext $context)
    {
        $owner = bagisto_graphql()->guard($this->guard)->user();
        $user = array();
        if(!empty($owner))
            $user = $this->adminRepository->findOrFail($owner->id);
        return $user;
    }
    public function updateAdminProfile($rootValue, array $args, GraphQLContext $context)
    {

        if ( !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }


        $owner = bagisto_graphql()->guard($this->guard)->user();
       // $data = $args['input'];
        $id = $owner->id;

        if(!empty($args['input']['name'])) {

            $data['name'] = $args['input']['name'];
        }

        if(!empty($args['input']['oldPassword']) && !empty($args['input']['newPassword']) && !empty($args['input']['password_confirmation'])){

            $data['oldPassword'] = $args['input']['oldPassword'];
            $data['newPassword'] = $args['input']['newPassword'];
            $data['password_confirmation'] = $args['input']['password_confirmation'];

            $validator = Validator::make($data, [
                'oldPassword' => 'nullable',
                'newPassword' => 'nullable',
                'password_confirmation' => 'nullable|required_with:newPassword|same:newPassword',

            ]);

            if ($validator->fails()) {
                throw new Exception($validator->messages());
            }

            if (Hash::check($data['oldPassword'], $owner->password)) {
                try {
                    if (!$data['newPassword']) {
                        unset($data['newPassword']);
                    } else {
                        $data['password'] = bcrypt($data['newPassword']);
                    }


                } catch (Exception $e) {
                    throw new Exception($e->getMessage());
                }
            } else {
                throw new Exception(trans('shop::app.customer.account.address.delete.wrong-password'));
            }
        }
        if(!empty($args['input']['removeImage']) && $args['input']['removeImage'] == true)
        {
            $data['image'] = '';
        }
        if(isset($args['image']) ) {
            $file = isset($args['image']) ? $args['image'] : null;
            try {
                if ($file != null) {
                    $image = basename($file) . '.' . $file->getClientOriginalExtension();
                    Storage::disk('admin')->put($image, $file->getContent());
                    $data['image'] = $image;
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }

        }

        $admin = $this->adminRepository->update($data, $id);

        return $admin;

    }
}
