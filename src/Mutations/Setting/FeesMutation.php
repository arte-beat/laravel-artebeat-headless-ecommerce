<?php

namespace Webkul\GraphQLAPI\Mutations\Setting;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Http\Controllers\Controller;
use Webkul\Core\Repositories\FeesRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class FeesMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Core\Repositories\CompanyDetailsRepository  $companyDetailsRepository
     * @return void
     */
    public function __construct(
       protected FeesRepository $feesRepository
    )
    {
        $this->guard = 'admin-api';

        auth()->setDefaultDriver($this->guard);
        
        $this->_config = request('_config');
    }


    // getShippingFee
    public function getShippingFee($rootValue, array $args, GraphQLContext $context)
    {
        $shippingFee = $this->feesRepository->find(1);
        if($shippingFee) {
            return $shippingFee;
        } else {
            throw new Exception("Error Fetching Shipping Fee");
        }
    }

    // getTaxPercentage
    public function getTaxPercentage($rootValue, array $args, GraphQLContext $context)
    {
        $taxPercentage = $this->feesRepository->find(2);
        if($taxPercentage) {
            return $taxPercentage;
        } else {
            throw new Exception("Error Fetching Tax Percentage");
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateShippingFee($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        
        $validator = Validator::make($data, [
            'shipping_fee' => 'required|numeric',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $shippingFee = $this->feesRepository->update($data, 1);
            if($shippingFee) {
               $shippingFee->success = "Successfully Updated";
               return $shippingFee;
            } else {
                throw new Exception("Error Updating Tax Percentage");
            }
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
    public function updateTaxPercentage($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        
        $validator = Validator::make($data, [
            'tax_percentage' => 'required|numeric|min:0|max:100',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $taxPercentage = $this->feesRepository->update($data, 2);
            if($taxPercentage) {
                $taxPercentage->success = "Successfully Updated";
                return $taxPercentage;
            } else {
                throw new Exception("Error Updating Tax Percentage");
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
