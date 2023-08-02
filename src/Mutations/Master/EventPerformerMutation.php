<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Illuminate\Support\Facades\Validator;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class EventPerformerMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\PromoterRepository  $promoterRepository
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
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
    public function sync($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'event_id' => 'numeric|required',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $eventId = $data['event_id'];
        $artists = isset($data['artists']) ? $data['artists'] :  [];
        $promoters = isset($data['promoters']) ? $data['promoters'] :  [];

        try {
            $event = $this->productRepository->syncEventPerformers($eventId, $artists, $promoters);
            return $event;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
