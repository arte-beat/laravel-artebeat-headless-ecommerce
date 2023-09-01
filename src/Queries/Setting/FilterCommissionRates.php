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
        $query = $query->with('event','category');
        if(isset($input['all']) && $input['all']) {
            return $query;
        } else {
            unset($input['all']);
        }

        $eventId = $input['event_id'] ?? null;
        $categoryId = $input['category_id'] ?? null;

        $query = $query->where(function($query) use($eventId, $categoryId) {
            if($eventId) {
                $query->where('type', 'event_commission')->where('event_id', $eventId);
            } else if($categoryId) {
                $query->where('type', 'category_commission')->where('category_id', $categoryId);
            }
        });

        $arguments = $this->getFilterParams($input);
        return $query->where($arguments);
    }
}