<?php

namespace Webkul\GraphQLAPI\Queries\Setting;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterCommissionRates extends BaseFilter
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
        $query = $query->with('event');
        if(isset($input['all']) && $input['all']) {
            return $query;
        } else {
            unset($input['all']);
        }
        $arguments = $this->getFilterParams($input);
        return $query->where($arguments);
    }
}