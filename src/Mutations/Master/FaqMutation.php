<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Illuminate\Support\Facades\Validator;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\FaqRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class FaqMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\FaqRepository  $faqRepository
     * @return void
     */
    public function __construct(
        protected FaqRepository $faqRepository,
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
            'question' => 'string|required',
            'answer' => 'string|required',
            'status' => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $faq = $this->faqRepository->create($data);
            return $faq;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function update($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];

        $validator = Validator::make($data, [
            'question' => 'string|required',
            'answer' => 'string|required',
            'status' => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $promoter = $this->faqRepository->update($data, $id);
            return $promoter;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $faqRepository = $this->faqRepository->findOrFail($id);

        try {
            if($faqRepository){
                $this->faqRepository->delete($id);
                return ['success' => trans('admin::app.response.delete-success', ['name' => 'Faq'])];
            }
        } catch(\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Faq']));
        }
    }


}
