<?php

namespace Webkul\GraphQLAPI\Queries\Shop\Product;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterEvents extends BaseFilter
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
        if(isset($arguments['name'])) {
            $name = strtolower(str_replace(" ", "-", $arguments['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }
        return $query;
    }
}
