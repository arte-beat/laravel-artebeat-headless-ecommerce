<?php

namespace Webkul\GraphQLAPI\Queries\Catalog;

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
        if(!empty($arguments['owner_type'])) {
            $query->where('owner_type', 'like', '%' . urldecode($arguments['owner_type']) . '%');
        }
        if(!empty($arguments['owner_id'])) {
            $query->where('owner_id', '=', $arguments['owner_id']);
        }
        return $query;
    }
}
