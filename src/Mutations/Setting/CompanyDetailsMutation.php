<?php

namespace Webkul\GraphQLAPI\Mutations\Setting;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Core\Http\Controllers\Controller;
use Webkul\Core\Repositories\CompanyDetailsRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class CompanyDetailsMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Core\Repositories\CompanyDetailsRepository  $companyDetailsRepository
     * @return void
     */
    public function __construct(
       protected CompanyDetailsRepository $companyDetailsRepository
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
            'email' => 'email',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $companyDetails = $this->companyDetailsRepository->update($data, $id);
            if($companyDetails) {
                $companyDetails->success = "Successfully Updated";
            }
            return $companyDetails;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
