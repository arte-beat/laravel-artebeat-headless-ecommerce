<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\TicketOrder;

use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\TicketOrder;
use Webkul\Core\Contracts\Validations\Slug;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Product\Repositories\TicketOrderRepository;
use Webkul\Product\Repositories\ProductRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TicketOrderMutation extends Controller
{
    /**
     * @var int
     */
    protected $id;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Product\Repositories\ProductRepository  $productRepository
     * @param  \Webkul\Product\Repositories\TicketOrderRepository  $ticketOrderRepository
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected TicketOrderRepository $ticketOrderRepository
    ) {
        $this->guard = 'api';
        auth()->setDefaultDriver($this->guard);
        $this->middleware('auth:' . $this->guard);
    }

    /**
     * Store the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $multipleData = $args['input'];
        foreach ($multipleData as $index => $data) {
            $validator = Validator::make($data, [
                'product_id'    => 'required',
                'first_name'    => 'string|required',
                'last_name'     => 'string|required',
                'email'         => 'email',
            ]);
            $product = $this->productRepository->findOrFail($data['product_id']);
            if ($validator->fails()) {
                throw new Exception($validator->messages());
            }
            try {
                $ticketOrder[$index] = $this->ticketOrderRepository->create($data);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }
        return $ticketOrder;
    }
}
