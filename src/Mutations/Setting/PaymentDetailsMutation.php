<?php

namespace Webkul\GraphQLAPI\Mutations\Setting;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Http\Controllers\Controller;
use Webkul\Core\Repositories\PaymentDetailsRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class PaymentDetailsMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Core\Repositories\PaymentDetailsRepository  $paymentDetailsRepository
     * @return void
     */
    public function __construct(
       protected PaymentDetailsRepository $paymentDetailsRepository
    )
    {
        $this->guard = 'admin-api';

        auth()->setDefaultDriver($this->guard);
        
        $this->_config = request('_config');
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
            'stripe_key' => 'required',
            'stripe_secret' => 'required',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $paymentDetails = $this->paymentDetailsRepository->update($data, $id);
            if($paymentDetails) {
                $paymentDetails->success = "Successfully Updated";
            }
            return $paymentDetails;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
