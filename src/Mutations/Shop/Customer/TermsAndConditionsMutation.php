<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use Exception;

use Illuminate\Support\Facades\Validator;
use Webkul\Customer\Http\Controllers\Controller;
use Webkul\Customer\Repositories\CustomerRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;

class TermsAndConditionsMutation extends Controller
{
    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Customer\Repositories\CustomerRepository  $customerRepository
     * @return void
     */
    public function __construct(
       protected CustomerRepository $customerRepository,

    )
    {
        $this->guard = 'api';

        auth()->setDefaultDriver($this->guard);
        
        $this->middleware('auth:' . $this->guard);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function editTermsAndConditions($rootValue, array $args, GraphQLContext $context)
    {
        $data = $args['input'];
        
        $validator = Validator::make($data, [
            'html_content'  => 'string|required',
        ]);
        
        if ($validator->fails()) {
            $errorMessage = [];
            foreach ($validator->messages()->toArray() as $field => $message) {
                $errorMessage[] = is_array($message) ? $message[0] : $message;
            }
            
            throw new CustomException(
                implode(" ,", $errorMessage),
                'Invalid Terms And Conditions Details.'
            );
        }

        try {
            $contactUsMessage = $this->customerRepository->editTermsAndConditions($data);
            if($contactUsMessage) {
                $contactUsMessage['success'] = "Terms And Conditions Saved Successfully.";
                return $contactUsMessage;
            }

        } catch (Exception $e) {
            throw new CustomException(
                $e->getMessage(),
                'Terms And Conditions Create.'
            );
        }
    }
}