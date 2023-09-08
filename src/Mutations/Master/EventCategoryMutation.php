<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\EventCategoryRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Product\Models\EventCategory;

class EventCategoryMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\EventCategoryRepository  $eventcategoryRepository
     * @return void
     */
    public function __construct(
        protected EventCategoryRepository $eventcategoryRepository,
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
            'type'  => 'required',
            'name'  => 'string|required|unique:event_category,name',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $eventcategory = $this->eventcategoryRepository->create($data);
            return $eventcategory;
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
            'type'  => 'required',
            'name'  => 'string|required|unique:event_category,name,' . $id,
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $eventCategory = $this->eventcategoryRepository->update($data, $id);
            return $eventCategory;
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
        $eventCategory = $this->eventcategoryRepository->findOrFail($id);

        try {
            $this->eventcategoryRepository->delete($id);
            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Event category'])];
        } catch(\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Event category']));
        }
    }

    public function filterEventCategory($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\EventCategory::query();
        if(isset($args['input']['name']) && !empty($args['input']['name'])) {
            $query->where('name', 'like', '%' . urldecode($args['input']['name']) . '%');
        }
        $query->orderBy('id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }
}
