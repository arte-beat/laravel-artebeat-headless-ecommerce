<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use Exception;
use Illuminate\Support\Facades\Validator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Webkul\Product\Models\ContactMsg;
use Webkul\Product\Repositories\ContactMsgRepository;
use function Webkul\GraphQLAPI\Mutations\Master\auth;
use function Webkul\GraphQLAPI\Mutations\Master\request;
use function Webkul\GraphQLAPI\Mutations\Master\trans;

class ContactMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\ContactMsgRepository  $contactmsgRepository
     * @return void
     */
    public function __construct(
        protected ContactmsgRepository $contactmsgRepository,
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
        $file = isset($args['file']) ? $args['file']  : null;

        $validator = Validator::make($data, [
            'name'   => 'string|required',
            'email'   => 'string|required',
            'subject'   => 'string|required',
            'message'   => 'string|required',
            'phone'   => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {

            $contactmsg = $this->contactmsgRepository->create($data);
            return $contactmsg;
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

        $file = isset($args['file']) ? $args['file']  : null;

        $validator = Validator::make($data, [
            'name'   => 'string|required',
            'email'   => 'string|required',
            'subject'   => 'string|required',
            'message'   => 'string|required',
            'phone'   => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new CustomException('validation message',$validator->messages());
        }

        try {
            $contactmsg = $this->contactmsgRepository->update($data, $id);

            return $contactmsg;
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
        $contactmsg = $this->contactmsgRepository->findOrFail($id);

        try {
            $this->contactmsgRepository->delete($id);
            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Contact Msgs'])];
        } catch(\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Contact Msgs']));
        }
    }

    public function filterContactmsgs($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Contactmsg::query();

        if(!empty($args['input']['name']) && !empty($args['input']['name'])) {
            $query->where('name', 'like', '%' . urldecode($args['input']['name']) . '%');
        }
        if(isset($args['input']['email'])) {
            $query->where('email', 'like', '%' . urldecode($args['input']['email']) . '%');
        }

        if(isset($args['input']['phone'])) {
            $query->where('phone', 'like', '%' . urldecode($args['input']['phone']) . '%');
        }

        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }
}
