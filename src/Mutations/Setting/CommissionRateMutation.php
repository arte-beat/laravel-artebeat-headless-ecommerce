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
            'type' => 'required|in:event_commission',
            'event_id' => 'required|exists:products,id',
            'rate' => 'required|numeric|min:0|max:100',
            'status' => 'in:0,1',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
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

        $commissionRate = $this->commissionRateRepository->find($data['id']);

        if($data['id'] == 1 || $data['id'] == 2 || $data['id'] == 3){
            if($commissionRate) {
                $data['event_id'] = null;
                $data['description'] = $commissionRate->description;

                if($commissionRate->type != $data['type']){
                    throw new Exception('Invalid Type for this Commission Rate');
                }
                
                $validator = Validator::make($data, [
                    'rate' => 'required|numeric|min:0|max:100',
                    'status' => 'in:0,1',
                ]);
            }
        } else {
            $validator = Validator::make($data, [
                'id' => 'required|not_in:1,2,3',
                'type' => 'required|in:event_commission',
                'event_id' => 'required|exists:products,id',
                'rate' => 'required|numeric|min:0|max:100',
                'status' => 'in:0,1',
            ]);
        }

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