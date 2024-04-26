<?php

namespace Webkul\GraphQLAPI\Queries\Catalog;

use Webkul\GraphQLAPI\Queries\BaseFilter;

class FilterTickets extends BaseFilter
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
        if(isset($arguments['ticket_type']) && isset($arguments['event_name'])) {
            $name = strtolower(str_replace(" ", "-", $arguments['event_name']));
            $query->select('booking_product_event_tickets.id', 'booking_product_event_tickets.price', 'booking_product_event_tickets.qty', 'booking_product_event_ticket_translations.name', 'booking_product_event_tickets.booking_product_id', 'products.sku', 'products.id as product_primary_id')
            ->join('booking_product_event_ticket_translations', 'booking_product_event_tickets.id', '=', 'booking_product_event_ticket_translations.booking_product_event_ticket_id')
                ->join('booking_products', 'booking_product_event_tickets.booking_product_id', '=', 'booking_products.id')
                ->join('products', 'booking_products.product_id', '=', 'products.id')
                ->where('booking_product_event_ticket_translations.name', 'like', '%' . urldecode($arguments['ticket_type']) . '%')
                ->where('products.sku', 'like', '%' . urldecode($name) . '%');
        }
        if(isset($arguments['ticket_type']) && !isset($arguments['event_name'])) {
            $query->leftJoin('booking_product_event_ticket_translations', 'booking_product_event_tickets.id', '=', 'booking_product_event_ticket_translations.booking_product_event_ticket_id')
                ->where('booking_product_event_ticket_translations.name', 'like', '%' . urldecode($arguments['ticket_type']) . '%');
        }

        if(isset($arguments['event_name']) && !isset($arguments['ticket_type'])) {
            $name = strtolower(str_replace(" ", "-", $arguments['event_name']));
            $query->leftJoin('booking_products', 'booking_product_event_tickets.booking_product_id', '=', 'booking_products.id')
                ->leftJoin('products', 'booking_products.product_id', '=', 'products.id')
                ->where('products.sku', 'like', '%' . urldecode($name) . '%');
        }
        return $query;
    }
}
