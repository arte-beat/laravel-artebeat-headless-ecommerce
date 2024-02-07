<?php

namespace Webkul\GraphQLAPI\Queries\Customer;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterContactUsMessages extends BaseFilter
{
    /**
     * filter the data .
     *
     * @param  object  $query
     * @param  array $input
     * @return \Illuminate\Http\Response
     */
    public function __invoke($query, $input)
    {
        $arguments = $this->getFilterParams($input);

        if(isset($arguments['first_name']) && isset($arguments['last_name'])) {
            $query->where(function ($nameQuery) use ($arguments) {
                $nameQuery->where('customers.first_name', 'LIKE', '%' . $arguments['first_name'] . '%');
                $nameQuery->orWhere('customers.last_name', 'LIKE', '%' . $arguments['last_name'] . '%');
            });
        }
        if(isset($arguments['phone'])) {
            $query->where('phone', 'like', '%' . urldecode($arguments['phone']) . '%');
        }
        if(isset($arguments['email'])) {
            $query->where('email', 'like', '%' . urldecode($arguments['email']) . '%');
        }
        if(isset($arguments['read_status'])) {
            $query->where('read_status', '=', urldecode($arguments['read_status']) );
        }

        return $query;
    }
}