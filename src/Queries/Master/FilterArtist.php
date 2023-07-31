<?php

namespace Webkul\GraphQLAPI\Queries\Master;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterArtist extends BaseFilter
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
        return $query->where('artist_name', 'like', '%' . urldecode($arguments['artist_name']) . '%');
    }
}
