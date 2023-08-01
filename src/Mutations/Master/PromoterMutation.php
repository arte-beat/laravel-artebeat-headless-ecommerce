<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Illuminate\Support\Facades\Validator;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\PromoterRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class PromoterMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\PromoterRepository  $artistRepository
     * @return void
     */
    public function __construct(
        protected PromoterRepository $artistRepository,
    )
    {
        $this->guard = 'admin-api';
        auth()->setDefaultDriver($this->guard);
        $this->_config = request('_config');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'promoter_name' => 'string|required',
            'promoter_artist_type' => 'numeric|required',
            'promoter_phone' => 'string|required',
            'promoter_email' => 'string|required',
            'promoter_status' => 'numeric|required',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $artist = $this->artistRepository->create($data);
            return $artist;
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
        $id = $args['id'];

        $validator = Validator::make($data, [
            'promoter_name' => 'string|required',
            'promoter_artist_type' => 'numeric|required',
            'promoter_phone' => 'string|required',
            'promoter_email' => 'string|required',
            'promoter_status' => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $artist = $this->artistRepository->update($data, $id);
            return $artist;
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
        if (! isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $artist = $this->artistRepository->findOrFail($id);

        try {
            $this->artistRepository->delete($id);
            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Promoter'])];
        } catch(\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Promoter']));
        }
    }
}
