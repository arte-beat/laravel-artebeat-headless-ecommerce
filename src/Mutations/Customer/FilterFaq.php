<?php

namespace Webkul\GraphQLAPI\Mutations\Customer;

use Exception;
use Illuminate\Support\Facades\Validator;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\FaqRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class FilterFaq extends Controller
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
        $this->guard = 'api';
        auth()->setDefaultDriver($this->guard);
//        $this->_config = request('_config');
        $this->middleware('auth:' . $this->guard);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function faqFilter($rootValue, array $args, GraphQLContext $context)
    {

        $status = 1; // Get the status argument from the query
        $query = \Webkul\Product\Models\Faq::query();

        // Apply the status filter
        $query->where('status', $status);

        // Paginate and return the results
        return $query->paginate(10);
    }


}
