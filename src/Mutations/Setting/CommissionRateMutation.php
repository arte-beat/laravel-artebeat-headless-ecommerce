<?php

namespace Webkul\GraphQLAPI\Mutations\Setting;

use Exception;
use Illuminate\Support\Facades\Validator;
use Webkul\Core\Http\Controllers\Controller;
use Webkul\Core\Repositories\CommissionRateRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class CommissionRateMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Core\Repositories\CommissionRateRepository  $commissionRateRepository
     * @return void
     */
    public function __construct(
       protected CommissionRateRepository $commissionRateRepository
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
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        
        $validator = \Validator::make($data, [
//            'event_id' => 'required_without:category_id|exists:products,id',
//            'category_id' => 'required_without:event_id|exists:event_category,id',
            'rate' => 'required|numeric|min:0|max:100',
            'type' => 'required',
            'status' => 'in:0,1',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            if(in_array($data['type'], ["global", "merchant", "showcase"])) {
                $commissions = $this->commissionRateRepository->where(["type" => "global_commission"])->first();
                if(!empty($commissions)){
                    throw new Exception(ucfirst($data['type'])." commission is already existed.");
                }
            }
            if(isset($data['category_id']) && isset($data['event_id'])){
                throw new Exception('Invalid Type. Please select either Category or Event'); 
            }
//            if(isset($data['category_id'])){
//                $data['type'] = 'category_commission';
//            } else {
//                $data['type'] = 'event_commission';
//            }

            $type = 'global_commission';
            if(isset($data['type'])){
                if($data['type'] == 'global'){
                    $type = 'global_commission';
                }
                if($data['type'] == 'event'){
                    $type = 'event_commission';
                }
                if($data['type'] == 'category'){
                    $type = 'category_commission';
                }
                if($data['type'] == 'merchant'){
                    $type = 'merchant_commission';
                }
                if($data['type'] == 'showcase'){
                    $type = 'showcase_commission';
                }
            }
            $data['type'] = $type;
            $commissionRate = $this->commissionRateRepository->store($data);
            if($commissionRate) {
                $commissionRate->success = "Successfully Added New Commission Rate";
            }
            return $commissionRate;
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
        $data['id'] = $args['id'];

        if($data['id'] == 1 || $data['id'] == 2){
            throw new Exception('Invalid Commission Rate. Please select a valid Commission Rate');
        }
        
        if((isset($data['category_id']) && isset($data['event_id'])) || (!isset($data['category_id']) && !isset($data['event_id']) && $data['id'] != 3)){
            throw new Exception('Invalid Type. Please select either Category or Event'); 
        }

        if(isset($data['category_id'])){
            $data['type'] = 'category_commission';
            $validator = Validator::make($data, [
                'category_id' => 'required|exists:event_category,id',
            ]);
            $data['event_id'] = null;
        } else {
            $validator = Validator::make($data, [
                'event_id' => 'required|exists:products,id',
            ]);
            $data['type'] = 'event_commission';
            $data['category_id'] = null;
        }

        $commissionRate = $this->commissionRateRepository->find($data['id']);

        if($data['id'] == 3 && $commissionRate){
            $data['event_id'] = null;
            $data['category_id'] = null;
            $data['type'] = $commissionRate->type;
        }

        $validator = Validator::make($data, [
            'rate' => 'required|numeric|min:0|max:100',
            'status' => 'in:0,1',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $commissionRate = $this->commissionRateRepository->update($data, $data['id']);
            if($commissionRate) {
                $commissionRate->success = "Successfully Updated Commission Rate";
            }
            return $commissionRate;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Delete the specified resource from storage.
     *
     * @param  int  $id
     * @return Boolean
     */
    public function delete($rootValue, array $args, GraphQLContext $context) {
        $validator = Validator::make($args,['id' => 'required|not_in:1,2,3']);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }
        $commissionRate = $this->commissionRateRepository->delete($args['id']);
        if($commissionRate['success'] == 'true'){
            return ['success' => 'Successfully Deleted Commission Rate'];
        } else {
            return ['success' => 'Unable to delete Commission Rate'];
        }
    }
}