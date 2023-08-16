<?php

namespace Webkul\GraphQLAPI\Queries\Customer;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterFaq extends BaseFilter
{
    /**
     * filter the data.
     *
     * @param  object  $query
     * @param  array $input
     * @return \Illuminate\Http\Response
     */
    public function __invoke($query, $input)
    {
        if (isset($input['question'])) {
            $query->where('question', 'like', '%'. $input['question']. '%');
        }

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        return $query;
    } 
}  
