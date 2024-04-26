<?php

namespace Webkul\GraphQLAPI\Queries\Shop\Product;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterPromoters extends BaseFilter
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
        return $query->where('promoter_name', 'like', '%' . urldecode($arguments['promoter_name']) . '%');
    }
}
