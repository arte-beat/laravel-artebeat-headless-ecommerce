<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use Exception;

use Illuminate\Support\Facades\Validator;
use Webkul\Customer\Http\Controllers\Controller;
use Webkul\Customer\Repositories\CustomerRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;

class ContactUsMutation extends Controller
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
    public function storeContactUsMessage($rootValue, array $args, GraphQLContext $context)
    {
        $data = $args['input'];
        $data['read_status'] = 1; //unread
        
        $validator = Validator::make($data, [
            'first_name'  => 'string|required',
            'last_name'  => 'string|required',
            'email'  => 'string',
            'subject' => 'string|required',
            'message' => 'string|required',
            'read_status' => 'int|required',
        ]);
        
        if ($validator->fails()) {
            $errorMessage = [];
            foreach ($validator->messages()->toArray() as $field => $message) {
                $errorMessage[] = is_array($message) ? $message[0] : $message;
            }
            
            throw new CustomException(
                implode(" ,", $errorMessage),
                'Invalid Contact-Us query Details.'
            );
        }

        try {
            $contactUsMessage = $this->customerRepository->storeContactUsMessage($data);
            if($contactUsMessage) {
                $contactUsMessage['success'] = "Contact Us Message Sent Successfully.";
                return $contactUsMessage;
            }

        } catch (Exception $e) {
            throw new CustomException(
                $e->getMessage(),
                'Contact Us Message Create.'
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function toggleReadStatus($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id']) && !bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.error-invalid-parameter'),
                'Invalid request parameter.'
            );
        }

        $id = $args['id'];
        
        try {
            $contactUsMessage = $this->customerRepository->toggleContactUsMessageReadStatus($id);

            if($contactUsMessage) {
                $contactUsMessage['success'] = "Contact Us Message Read Status Changed Successfully.";
                return $contactUsMessage;
            }
  
        } catch (Exception $e) {
            throw new CustomException(
                $e->getMessage(),
                'Address remove Failed.'
            );
        }
    }
}