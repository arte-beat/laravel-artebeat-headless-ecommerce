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
        if(!empty($arguments['artist_name']) && !empty($arguments['artist_type'])) {
            return $query->where('artist_name', 'like', '%' . urldecode($arguments['artist_name']) . '%')->where('artist_type', '=', $arguments['artist_type']);
        }
        if(isset($arguments['artist_name'])) {
            return $query->where('artist_name', 'like', '%' . urldecode($arguments['artist_name']) . '%');
        }
        if(!empty($arguments['artist_type'])) {
            return $query->where('artist_type', '=', $arguments['artist_type']);
        }
    }
}
