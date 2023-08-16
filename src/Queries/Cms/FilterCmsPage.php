<?php

namespace Webkul\GraphQLAPI\Queries\Cms;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterCmsPage extends BaseFilter
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
        $arguments = $this->getFilterParams($input);

        if (isset($arguments['page_title'])) {
            $query->where('page_title', 'like', '%'. $arguments['page_title']. '%');
        }

        if (isset($arguments['url_key'])) {
            $query->where('url_key', 'like', '%'. $arguments['url_key']. '%');
        }

        if (isset($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        if (isset($arguments['meta_title'])) {
            $query->where('meta_title', 'like', '%'. $arguments['meta_title']. '%');
        }

        if (isset($arguments['meta_keywords'])) {
            $query->where('meta_keywords', 'like', '%'. $arguments['meta_keywords']. '%');
        }

        return $query;
    } 
}  
