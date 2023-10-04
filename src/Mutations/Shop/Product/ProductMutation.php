<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Product;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Webkul\Product\Helpers\ProductType;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductImage;
use Webkul\Product\Models\ProductFlat;
use Webkul\Core\Contracts\Validations\Slug;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductFlatRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\PromoterRepository;
use Webkul\Product\Repositories\ArtistRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ShowcaseRepository;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Product\Models\Promoter;
use Webkul\Product\Models\Showcase;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Customer\Repositories\CustomerRepository;
use App\Events\SendMailForEventCreate;

class ProductMutation extends Controller
{
    /**
     * @var int
     */
    protected $id;

    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\ProductRepository $productRepository
     * @param \Webkul\Product\Repositories\ProductFlatRepository $productFlatRepository
     * @param \Webkul\Product\Repositories\ProductAttributeValueRepository $productAttributeValueRepository
     * @param \Webkul\Customer\Repositories\CustomerRepository $customerRepository
     * @return void
     */
    public function __construct(
        protected ProductRepository               $productRepository,
        protected PromoterRepository              $promoterRepository,
        protected ArtistRepository                $artistRepository,
        protected ProductFlatRepository           $productFlatRepository,
        protected ProductAttributeValueRepository $productAttributeValueRepository,
        protected ShowcaseRepository              $showcaseRepository,
        protected CustomerRepository              $customerRepository
    )
    {
        $this->guard = 'api';
        auth()->setDefaultDriver($this->guard);
//        $this->_config = request('_config');
        $this->middleware('auth:' . $this->guard);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventFilter($rootValue, array $args, GraphQLContext $context)
    {

        $query = \Webkul\Product\Models\Product::query();
        $query->with('booking_product');
        $query->where('type', 'booking');
        $owner = bagisto_graphql()->guard($this->guard)->user();
        $query->where('event_status', 1);
        // owner_id: Int
        if (!empty($owner) && $owner->customer_type == 2) {
            $query->orWhere('owner_id', $owner->id);
           // $query->Where('owner_type', 'customer');
        }

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }

        if (!empty($args['input']['owner_type'])) {
            $query->where('owner_type', 'like', '%' . urldecode($args['input']['owner_type']) . '%');
        }

        if (!empty($args['input']['owner_id'])) {
            $query->where('owner_id', '=', $args['input']['owner_id']);
        }

        if (!empty($args['input']['is_feature_event'])) {
            $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
        }

        if (!empty($args['input']['is_hero_event'])) {
            $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
        }

        if (isset($args['input']['event_status'])) {
            $query->where('event_status', '=', $args['input']['event_status']);
        }

        if (isset($args['input']['search_text'])) {
            // Match artist, promoter, location and event name
            $searchedName = strtolower(str_replace(" ", "-", $args['input']['search_text']));
            $query->where('sku', 'like', '%' . urldecode($searchedName) . '%');

            $query->orWhereHas('promoters', function ($promoterQuery) use ($args) {
                $promoterQuery->where('promoter_name', 'like', '%' . $args['input']['search_text'] . '%');
            });

            $query->orWhereHas('artists', function ($artistQuery) use ($args) {
                $artistQuery->where('artist_name', 'like', '%' . $args['input']['search_text'] . '%');
            });
        }


        $query->whereHas('booking_product', function ($bookingQuery) use ($args, $query) {

            if (isset($args['input']['event_category'])) {
                $bookingQuery->where('event_type', $args['input']['event_category']);
            }

            if (isset($args['input']['weekly_events'])) {
                $bookingQuery->where('booking_products.available_every_week', '=', 1);
            }

            if (isset($args['input']['ticket_price_min']) && isset($args['input']['ticket_price_max'])) {
                $bookingQuery->whereHas('event_tickets', function ($eventTicketQuery) use ($args) {
                    $eventTicketQuery->where('price', '>=', $args['input']['ticket_price_min']);
                    $eventTicketQuery->where('price', '<=', $args['input']['ticket_price_max']);
                });
            }

            if (isset($args['input']['distance_min']) && isset($args['input']['distance_max']) && isset($args['input']['client_location_longitude']) && isset($args['input']['client_location_latitude'])) {

                $clientLongitude = $args['input']['client_location_longitude'];
                $clientLatitude = $args['input']['client_location_latitude'];
                $minDistance = $args['input']['distance_min'];
                $maxDistance = $args['input']['distance_max'];

                // * 6371000 for meters, 6371 for kilometer and 3956 for miles
                $bookingQuery->selectRaw("(6371000 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance_from_client", [$clientLatitude, $clientLongitude, $clientLatitude]);

                $bookingQuery->having('distance_from_client', '>', $minDistance);
                $bookingQuery->having('distance_from_client', '<', $maxDistance);
            }
        });

        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);

    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMerchantByEvent($rootValue, array $args, GraphQLContext $context)
    {
        $productId = $args['id'];

        $query = \Webkul\Product\Models\Product::query();

        // @dd($query);

        $query->find($productId)->where('type', 'simple');

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }

        $query = $query->distinct()
            ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
            ->addSelect('products.*');
        $query->orderBy('products.id', 'desc');

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }

        if (!empty($args['input']['is_feature_event'])) {
            $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
        }

        if (!empty($args['input']['is_hero_event'])) {
            $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
        }

        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;

        return $query->paginate($count, ['*'], 'page', $page);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getShowcaseCollectionByShowcase($rootValue, array $args, GraphQLContext $context)
    {
        $showcaseId = $args['id'];

        $collection = $this->showcaseRepository->getShowcaseCollectionByShowcaseId($showcaseId);

        if ($collection) {
            $count = isset($args['first']) ? $args['first'] : 10;
            $page = isset($args['page']) ? $args['page'] : 1;

            return $collection->paginate($count, ['*'], 'page', $page);
        }
        return null;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPastEvents($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();

        $today = date('Y-m-d h:i:s');
        $query->where('products.type', 'booking');
//        $query->where('products.event_status', '=', 1);
        $owner = bagisto_graphql()->guard($this->guard)->user();

        $query->where('products.owner_id', '=', $owner->id);

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('products.sku', 'like', '%' . urldecode($name) . '%');
        }
        $query = $query->distinct()
            ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
            ->addSelect('products.*')
            ->where('booking_products.available_to', '<', $today);
        $query->orderBy('products.id', 'desc');

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }

        if (!empty($args['input']['is_feature_event'])) {
            $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
        }

        if (!empty($args['input']['is_hero_event'])) {
            $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
        }

        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFutureEvents($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();

        $today = date('Y-m-d h:i:s');
        $query->where('products.type', 'booking');
//        $query->where('products.event_status', '=', 1);
        $owner = bagisto_graphql()->guard($this->guard)->user();

        $query->where('products.owner_id', '=', $owner->id);
        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('products.sku', 'like', '%' . urldecode($name) . '%');
        }
        $query = $query->distinct()
            ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
            ->addSelect('products.*')
            ->where('booking_products.available_from', '>=', $today);
        $query->orderBy('products.id', 'desc');

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }

        if (!empty($args['input']['is_feature_event'])) {
            $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
        }

        if (!empty($args['input']['is_hero_event'])) {
            $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
        }

        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    public function getOngoingEvents($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();

        $now = now();
        $query->where('products.type', 'booking');
        // $query->where('products.event_status', '=', 1);
        $owner = bagisto_graphql()->guard($this->guard)->user();
        $query->where('products.owner_id', '=', $owner->id);

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('products.sku', 'like', '%' . urldecode($name) . '%');
        }
        $query = $query->distinct()
            ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
            ->addSelect('products.*')
            ->whereRaw('? between booking_products.available_from and booking_products.available_to', [$now]);

        $query->orderBy('products.id', 'desc');

        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }

        if (!empty($args['input']['is_feature_event'])) {
            $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
        }

        if (!empty($args['input']['is_hero_event'])) {
            $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
        }

        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    public function similarEventFilter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();
        $query = $query
            ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
            ->addSelect('products.*')
            ->where('products.id', '!=', $args['input']['event_id'])
            ->where('products.type', '=', 'booking')
            ->where('products.event_status', '=', 1)
            ->where('booking_products.event_type', '=', $args['input']['event_type'])
            ->where('booking_products.available_from', '>=', date('Y-m-d'))
            ->orderBy('products.id', 'DESC');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;

        $similarProducts = $query->paginate($count, ['*'], 'page', $page);
        return $similarProducts;
    }

    public function particularEventFilter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();

        if (!empty($args['input']['weekly_events'])) {

            $lastDayOfWeek = date('Y-m-d', strtotime('next Sunday'));
            $today = date('Y-m-d');
            $query->where('products.type', 'booking');
            $query->where('products.event_status', '=', 1);
            if (isset($args['input']['name'])) {
                $name = strtolower(str_replace(" ", "-", $args['input']['name']));
                $query->where('products.sku', 'like', '%' . urldecode($name) . '%');
            }
            $query = $query->distinct()
                ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
                ->addSelect('products.*')
                ->where('booking_products.available_from', '<=', $lastDayOfWeek)
                ->where('booking_products.available_from', '>=', $today);
            $query->orderBy('products.id', 'desc');

        } else {
            $query->where('type', 'booking');
            $query->where('products.event_status', '=', 1);
            if (isset($args['input']['name'])) {
                $name = strtolower(str_replace(" ", "-", $args['input']['name']));
                $query->where('sku', 'like', '%' . urldecode($name) . '%');
            }
            if (!empty($args['input']['is_feature_event'])) {
                $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
            }
            if (!empty($args['input']['is_hero_event'])) {

                $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
            }
            $query->orderBy('id', 'desc');
        }

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    public function featureEventFilter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();

        if (!empty($args['input']['weekly_events'])) {

            $lastDayOfWeek = date('Y-m-d', strtotime('next Sunday'));
            $today = date('Y-m-d');
            $query->where('products.type', 'booking');
            $query->where('products.event_status', '=', 1);
            if (isset($args['input']['name'])) {
                $name = strtolower(str_replace(" ", "-", $args['input']['name']));
                $query->where('products.sku', 'like', '%' . urldecode($name) . '%');
            }
            $query = $query->distinct()
                ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
                ->addSelect('products.*')
                ->where('booking_products.available_from', '<=', $lastDayOfWeek)
                ->where('booking_products.available_from', '>=', $today);
            $query->orderBy('products.id', 'desc');

        } else {
            $query->where('type', 'booking');
            $query->where('products.event_status', '=', 1);
            if (isset($args['input']['name'])) {
                $name = strtolower(str_replace(" ", "-", $args['input']['name']));
                $query->where('sku', 'like', '%' . urldecode($name) . '%');
            }
            if (!empty($args['input']['is_feature_event'])) {
                $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
            }
            if (!empty($args['input']['is_hero_event'])) {

                $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
            }
            $query->orderBy('id', 'desc');
        }

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    /**
     * Store the specified resource in storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function storeEventBooking($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));

        $validator = Validator::make($data, [
            'name' => 'string|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $event = new Product();
        $eventdata = $event::where('sku', '=', $data['sku'])->first();

        if (!empty($eventdata)) {
            throw new Exception("{\"name\":[\"The name has already been taken.\"]}");
        }

        $data['type'] = 'booking';
        $data['attribute_family_id'] = 1;
        $data['status'] = 1;

        try {
            $owner = bagisto_graphql()->guard($this->guard)->user();
            $data['owner_id'] = $owner->id;
            $data['owner_type'] = 'customer';
            $updatedData['customer_type'] = 2; // Event Manager
            $customer = $this->customerRepository->update($updatedData, $owner->id);

            Event::dispatch('catalog.product.create.before');
            $product = $this->productRepository->create($data);
            Event::dispatch('catalog.product.create.after', $product);
            SendMailForEventCreate::dispatch($owner, $customer['email'], 3);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        if (!empty($product)) {
            $id = $product->id;
            // Only in case of booking product type
            if (isset($product->type) && $product->type == 'booking' && isset($data['booking']) && $data['booking']) {
                $data['booking']['event_pwd'] = Str::random(10);
                $data['booking'] = bagisto_graphql()->manageBookingRequest($data['booking']);
            }
            try {
                Event::dispatch('catalog.product.update.before', $id);
                $updateProduct = $this->productRepository->update($data, $id);
                Event::dispatch('catalog.product.update.after', $updateProduct);
                return $updateProduct;
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        } else {
            throw new Exception("Unable to process at the moment. Please try again after sometime.");
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function updateEventBooking($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];

        $product = $this->productRepository->findOrFail($id);

        $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));

        $validator = Validator::make($data, [
            'name' => 'string|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $event = new Product();
        $eventdata = $event::where('sku', '=', $data['sku'])->where('id', '!=', $id)->first();

        if (!empty($eventdata)) {
            throw new Exception("{\"name\":[\"The name has already been taken.\"]}");
        }
        if (!empty($product)) {
            // Only in case of booking product type
            if (isset($product->type) && $product->type == 'booking' && isset($data['booking']) && $data['booking']) {
                $data['booking'] = bagisto_graphql()->manageBookingRequest($data['booking']);
            }

            try {
                Event::dispatch('catalog.product.update.before', $id);
                $updateProduct = $this->productRepository->update($data, $id);
                Event::dispatch('catalog.product.update.after', $updateProduct);
                return $updateProduct;
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        } else {
            throw new Exception("Unable to process at the moment. Please try again after sometime.");
        }
    }

    /**
     * Store the specified resource in storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function storeMerchantEventBooking($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $multipleData = $args['input'];
        $multipleFiles = $args['files'];
        foreach ($multipleData as $index => $data) {
            $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
            $validator = Validator::make($data, [
                'name' => 'string|required',
            ]);

            if ($validator->fails()) {
                throw new Exception($validator->messages());
            }
            $product = $this->productRepository->findOrFail($data['product_id']);
            $event = new Product();
            $eventdata = $event::where('sku', '=', $data['sku'])->first();
            $product = $this->productRepository->findOrFail($data['product_id']);
            if (!empty($eventdata)) {
                throw new Exception("{\"name\":[\"The name has already been taken.\"]}");
            }
            $data['type'] = 'simple';
            $data['attribute_family_id'] = 1;
            $data['status'] = 1;
            $data['parent_id'] = $data['product_id'];
            try {
                $owner = bagisto_graphql()->guard($this->guard)->user();
                $data['owner_id'] = $owner->id;
                $data['owner_type'] = 'admin';

                Event::dispatch('catalog.product.create.before');
                $product = $this->productRepository->create($data);
                Event::dispatch('catalog.product.create.after', $product);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }

            if (!empty($product)) {
                $id = $product->id;
                try {
                    Event::dispatch('catalog.product.update.before', $id);
                    $updateProduct[$index] = $this->productRepository->update($data, $id);
                    ProductFlat::where('product_id', $id)->update(['merch_type' => $data['merch_type']]);
                    Event::dispatch('catalog.product.update.after', $updateProduct[$index]);

                    if ($multipleFiles != null) {
                        if (isset($multipleFiles[$index])) {
                            $files = $multipleFiles[$index];
                            bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
                        }
                    }

                    $this->productRepository->syncQuantities($id, $data['quantity']);
                } catch (Exception $e) {
                    throw new Exception($e->getMessage());
                }
            } else {
                throw new Exception("Unable to process at the moment. Please try again after sometime.");
            }
//            }
        }
        return $updateProduct;
    }


    /**
     * Store the specified resource in storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function updateMerchantEventBooking($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $multipleData = $args['input'];
        $multipleDeleteData = $args['deleteInput'];
        $multipleFiles = $args['files'];
        foreach ($multipleDeleteData as $deleteData) {
            Product::where("id", "=", $deleteData['id'])->delete();
            ProductImage::where("product_id", "=", $deleteData['id'])->delete();
        }
        foreach ($multipleData as $index => $data) {
            if (isset($data['product_id']) && empty($data['id'])) {
                $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
                $validator = Validator::make($data, [
                    'sku' => ['required', 'unique:products,sku', new Slug],
                ]);

                if ($validator->fails()) {
                    throw new Exception($validator->messages());
                }
                $data['type'] = 'simple';
                $data['attribute_family_id'] = 1;
                $data['parent_id'] = $data['product_id'];
                try {
                    $owner = bagisto_graphql()->guard($this->guard)->user();
                    $data['owner_id'] = $owner->id;
                    $data['owner_type'] = 'admin';

                    Event::dispatch('catalog.product.create.before');
                    $product = $this->productRepository->create($data);
                    Event::dispatch('catalog.product.create.after', $product);
                } catch (Exception $e) {
                    throw new Exception($e->getMessage());
                }

                if (!empty($product)) {
                    $id = $product->id;
                    try {
                        Event::dispatch('catalog.product.update.before', $id);
                        $updateProduct[$index] = $this->productRepository->update($data, $id);
                        ProductFlat::where('product_id', $id)->update(['merch_type' => $data['merch_type']]);
                        Event::dispatch('catalog.product.update.after', $updateProduct[$index]);

                        if ($multipleFiles != null) {
                            if (isset($multipleFiles[$index])) {
                                $files = $multipleFiles[$index];
                                bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
                            }
                        }

                        $this->productRepository->syncQuantities($id, $data['quantity']);
                    } catch (Exception $e) {
                        throw new Exception($e->getMessage());
                    }
                } else {
                    throw new Exception("Unable to process at the moment. Please try again after sometime.");
                }
            } else {
                $id = $data['id'];
                $product = $this->productRepository->findOrFail($id);
                if (!empty($product)) {
                    try {
                        $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
                        $validator = Validator::make($data, [
                            'sku' => ['required', 'unique:products,sku,' . $id, new Slug],
                        ]);

                        if ($validator->fails()) {
                            throw new Exception($validator->messages());
                        }
                        Event::dispatch('catalog.product.update.before', $id);
                        $updateProduct[$index] = $this->productRepository->update($data, $id);
                        ProductFlat::where('product_id', $id)->update(['merch_type' => $data['merch_type']]);
                        Event::dispatch('catalog.product.update.after', $updateProduct[$index]);

                        if (!empty($data['removeImages'])) {
                            $removeImagesArr = $data['removeImages'];
                            foreach ($removeImagesArr as $removeImage) {
                                ProductImage::where("id", "=", $removeImage['id'])->delete();
                            }
                        }
                        if ($multipleFiles != null) {
                            if (isset($multipleFiles[$index])) {
                                $files = $multipleFiles[$index];
                                bagisto_graphql()->uploadMerchantImages($files, $product, 'product/', 'image');
                            }
                        }
                        $this->productRepository->syncQuantities($id, $data['quantity']);
                    } catch (Exception $e) {
                        throw new Exception($e->getMessage());
                    }
                } else {
                    throw new Exception("Unable to process at the moment. Please try again after sometime.");
                }
            }
        }
        return $updateProduct;
    }

    public function uploadEventImages($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['product_id']) || (isset($args['product_id']) && !$args['product_id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['product_id'];
        $product = $this->productRepository->findOrFail($id);
        if (!empty($product)) {
            if (isset($product->id)) {
                $files = $args['files'];
                if ($files != null) {
                    bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
                }
                return $product;
            }
        } else {
            throw new Exception("Unable to process at the moment. Please try again after sometime.");
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function deleteEventBooking($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $product = $this->productRepository->findOrFail($id);
        try {
            $this->productRepository->delete($id);
            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Event'])];
        } catch (\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Event']));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];

        $product = $this->productRepository->findOrFail($id);

        try {
            $this->productRepository->delete($id);

            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Product'])];
        } catch (\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Product']));
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function storePromoter($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        $validator = Validator::make($data, [
            'promoter_name' => 'string|required',
            // 'promoter_artist_type' => 'numeric|required',
            // 'promoter_phone' => 'string|required',
            // 'promoter_email' => 'string|required',
            // 'promoter_status' => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $promoter = $this->promoterRepository->create($data);
            return $promoter;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function updatePromoter($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];

        $validator = Validator::make($data, [
            'promoter_name' => 'string|required',
            // 'promoter_artist_type' => 'numeric|required',
            // 'promoter_phone' => 'string|required',
            // 'promoter_email' => 'string|required',
            // 'promoter_status' => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            $promoter = $this->promoterRepository->update($data, $id);
            return $promoter;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function deletePromoter($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $promoter = $this->promoterRepository->findOrFail($id);

        try {
            if ($promoter) {
                $this->promoterRepository->delete($id);
                return ['success' => trans('admin::app.response.delete-success', ['name' => 'Promoter'])];
            }
        } catch (\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Promoter']));
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncEventPerformer($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $eventId = $args['product_id'];

        $artistIds = $data['artistIds'];
        $artistNames = $data['artistNames'];
        $artistTypes = $data['artistTypes'];

        $promoterIds = $data['promoterIds'];
        $promoterNames = $data['promoterNames'];
        $promoterTypes = $data['promoterTypes'];

        // $artistIds = isset($data['artists']) ? $data['artists'] :  [];
        // $promoterIds = isset($data['promoters']) ? $data['promoters'] :  [];

        if (count($artistIds) + count($artistNames) != count($artistTypes)) {
            throw new Exception('Number of Artist types do not much number of Artists provided');
        }
        if (count($promoterIds) + count($promoterNames) != count($promoterTypes)) {
            throw new Exception('Number of Promoter types do not much number of Promoters provided');
        }

        try {
            // Register Artists and promoters
            if (count($artistNames) != 0) {
                $newArtists = $this->artistRepository->createArtistsByName($artistNames);
                foreach ($newArtists as $artist) {
                    array_push($artistIds, $artist->id);
                }
            }
            if (count($promoterNames) != 0) {
                $newPromoters = $this->promoterRepository->createPromotersByName($promoterNames);
                foreach ($newPromoters as $promoter) {
                    array_push($promoterIds, $promoter->id);
                }
            }
            $event = $this->productRepository->syncEventPerformers($eventId, $artistIds, $promoterIds, $artistTypes, $promoterTypes);
            return $event;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function filterPromoter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Promoter::query();

        if (isset($args['input']['promoter_name']) && !empty($args['input']['promoter_name'])) {

            $query->where('promoter_name', 'like', '%' . urldecode($args['input']['promoter_name']) . '%');
        }
        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    public function getShowcase($rootValue, array $args, GraphQLContext $context)
    {
        $showcase = Showcase::latest()->first();
        if ($showcase) {
            return $showcase;
        }

        return null;
    }

    public function filterEventCategory($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\EventCategory::query();
        if (isset($args['input']['name']) && !empty($args['input']['name'])) {
            $query->where('name', 'like', '%' . urldecode($args['input']['name']) . '%');
        }
        $query->orderBy('id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    public function getAttemptEventsMerchant($rootValue, array $args, GraphQLContext $context)
    {
        $responseData = [];
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $owner = bagisto_graphql()->guard($this->guard)->user();
        $args['input']['email'] = $owner->email;
        $query->whereHas('bookedProduct', function ($getAttemptedProducts) use ($args) {
            if (!empty($args['input']['email']))
                $getAttemptedProducts->where('orders.customer_email', '=', $args['input']['email']);
        });
        $query->where('type', 'booking');
        $result = $query->get();
        if (count($result) > 0) {
            foreach ($result as $index => $product) {
                $merchants = $product->listOfBookedProductsmerchants($args['input']['limit']);
                foreach ($merchants as $merchantindex => $merchant) {
                    $responseData[$index][$merchantindex] = $merchant;
                }
            }
        }
        return $responseData;
    }

    public function topsellingMerchant($rootValue, array $args, GraphQLContext $context)
    {
        $responseData = [];
        if (!empty($args["input"]["distance"])) {
            $distance = $args["input"]["distance"];
        } else {
            $distance = 100;
        }
        $queryBuilder = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->leftJoin('booking_products', 'products.parent_id', '=', 'booking_products.product_id')
            ->addSelect('products.*', 'booking_products.latitude', 'booking_products.longitude')
            ->selectRaw('COUNT(cart_items.id) AS total_sold')
            ->selectRaw('SQRT( POW(69.1 * (booking_products.latitude - ' . $args["input"]["latitude"] . '), 2) + POW(69.1 * (' . $args["input"]["longitude"] . ' - booking_products.longitude) * COS(booking_products.latitude / 57.3), 2)) as distance')
            ->where('products.type', 'simple')
            ->where('orders.status', 'completed')
            ->whereNULL('products.product_type')
            ->groupBy('cart_items.product_id')
            ->havingRaw(' distance <= ' . $distance)
            ->where('products.event_status', 1)
            ->orderBy('total_sold', 'desc')->get();

        if (count($queryBuilder) > 0) {
            foreach ($queryBuilder as $index => $product) {
                $responseData[$index] = $this->productRepository->findOrFail($product->id);

            }
        }
        return $responseData;
    }

    public function getRandomMerchant($rootValue, array $args, GraphQLContext $context)
    {
        $responseData = [];

        if (!empty($args["input"]["distance"])) {
            $distance = $args["input"]["distance"];
        } else {
            $distance = 800;
        }
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $result = $query->leftJoin('booking_products', 'products.parent_id', '=', 'booking_products.product_id')
            ->leftJoin('product_qty_size', 'product_qty_size.product_id', '=', 'products.id')
            ->addSelect('products.*')
            ->SelectRaw('SUM(product_qty_size.qty) as total_sold')
            ->selectRaw('SQRT( POW(69.1 * (booking_products.latitude - ' . $args["input"]["latitude"] . '), 2) + POW(69.1 * (' . $args["input"]["longitude"] . ' - booking_products.longitude) * COS(booking_products.latitude / 57.3), 2)) as distance')
            ->where('products.type', 'simple')
            ->where('products.event_status', 1)
            ->whereNULL('products.product_type')
            ->groupBy('products.id')
            ->havingRaw(' total_sold > 0 and distance <= ' . $distance)
            ->inRandomOrder()->get();
        if (count($result) > 0) {
            foreach ($result as $index => $product) {
                $responseData[$index] = $product;

            }
        }
        return $responseData;
    }

    public function getMyeventsDataByEventOrganizer($rootValue, array $args, GraphQLContext $context)
    {
        $product = $this->productRepository->findOrFail($args['product_id']);
        $merchants = [];
        $prefix = DB::getTablePrefix();
        $merchants = \Webkul\GraphQLAPI\Models\Catalog\Product::query()->where('parent_id', 1)->pluck('id');
        $merchants->push($args['product_id']);
        $result_price = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->Select('orders.grand_total', 'products.id')
            ->where('orders.status', 'completed')
            ->whereIn('products.id', $merchants)
            ->groupBy('cart_items.product_id')->get();

        foreach ($result_price as $individual_price) {
            $product['total_price'] += $individual_price->grand_total;
        }
        $result_product = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->SelectRaw('SUM(' . $prefix . 'cart_items.quantity) as total_sold')
            ->where('products.type', 'booking')
            ->where('orders.status', 'completed')
            ->where('cart_items.product_id', $args['product_id'])
            ->groupBy('cart_items.product_id')->first();

        $result_merchant = DB::table('orders')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('products', 'cart_items.product_id', '=', 'products.id')
            ->SelectRaw('SUM(' . $prefix . 'cart_items.quantity) as total_sold')
            ->where('products.type', 'simple')
            ->whereNULL('products.product_type')
            ->where('orders.status', 'completed')
            ->where('products.parent_id', $args['product_id'])->first();


        if (!empty($result_merchant)) {
            $product['noOfMerchant'] = $result_merchant->total_sold ?? 0;
        }
        if (!empty($result_product)) {
            $product['noOfTickets'] = $result_product->total_sold ?? 0;
        }


        return $product;
    }

    public function getBookedTicketsByEventOrganizer($rootValue, array $args, GraphQLContext $context)
    {

       $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $query
            ->leftJoin('booked_event_tickets_history', 'booked_event_tickets_history.product_id', '=', 'products.id')
            ->leftJoin('orders', 'booked_event_tickets_history.orderId', '=', 'orders.id')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('booking_product_event_ticket_translations', 'booked_event_tickets_history.ticket_id', '=', 'booking_product_event_ticket_translations.booking_product_event_ticket_id')
            ->select('orders.customer_first_name as firstname', 'orders.customer_last_name as lastname', 'orders.customer_email as email', 'orders.created_at','cart_items.ticket_id', 'orders.id AS order_id', 'booking_product_event_ticket_translations.name as ticketType', 'cart_items.base_price as price','orders.status')
            ->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name")
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('cart_items.product_id', $args['product_id'])
            ->where('booked_event_tickets_history.product_id', $args['product_id'])
             ->groupBy('booked_event_tickets_history.id')
            ->orderBy('booked_event_tickets_history.id','desc');
        if (!empty($args['input']['name'])) {
            if (is_numeric(($args['input']['name']))) {
                $query->where('orders.id', $args['input']['name']);
            } else {
                $query->having("customer_name", "like", "%" . $args['input']['name'] . "%");
            }
        }

        $query->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);


    }

    public function getBookedMerchantListByEvent($rootValue, array $args, GraphQLContext $context)
    {
        $res = [];
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query()
            ->leftJoin('cart_items', 'products.id', '=', 'cart_items.product_id')
            ->leftJoin('orders', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->leftJoin('order_status_for_single_product', 'orders.id', '=', 'order_status_for_single_product.orderId')
            ->addSelect('products.id', 'products.sku as productName', 'orders.created_at', 'cart_items.quantity', 'cart_items.ticket_id', 'orders.id AS order_id', 'addresses.address_type', 'addresses.first_name', 'addresses.last_name', 'addresses.address1', 'addresses.address2', 'addresses.postcode', 'addresses.city', 'addresses.state', 'addresses.country', 'addresses.email', 'addresses.phone', 'cart_items.total as price', 'orders.status', 'cart_items.base_price as basePrice', 'cart_items.quantity as purchasedQuantity','order_status_for_single_product.status as deliveryStatus','orders.status')
            ->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name")
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('addresses.default_address', 1)
            ->where('products.type', 'simple')
            ->whereNULL('products.product_type');
        if (!empty($args['product_id'])) {
            $query->where('products.parent_id', $args['product_id']);
        } else {
            $owner = bagisto_graphql()->guard($this->guard)->user();
            $query->where('orders.customer_email', $owner->email);
        }
        if (!empty($args['input']['name'])) {
            if (is_numeric(($args['input']['name']))) {
                $query->where('orders.id', $args['input']['name']);
            } else {
                $query->having("customer_name", "like", "%" . $args['input']['name'] . "%");
            }
        }

        $query->groupBy('cart_items.ticket_id', 'cart_items.cart_id')->orderBy('orders.id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        $res = $query->paginate($count, ['*'], 'page', $page);
        return $res;
    }

    public function downloadMerchTickets($rootValue, array $args, GraphQLContext $context)
    {
        $response = [];
        $arr_merchantList = [];
        $owner = bagisto_graphql()->guard($this->guard)->user();
        $merchantList = \Webkul\GraphQLAPI\Models\Catalog\Product::query()
            ->leftJoin('cart_items', 'products.id', '=', 'cart_items.product_id')
            ->leftJoin('orders', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('order_status_for_single_product', 'orders.id', '=', 'order_status_for_single_product.orderId')
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->leftJoin('product_qty_size', 'cart_items.ticket_id', '=', 'product_qty_size.id')
            ->addSelect('products.id', 'products.sku as productName', 'orders.created_at', 'cart_items.quantity', 'cart_items.ticket_id', 'orders.id AS order_id', 'addresses.address_type', 'addresses.first_name', 'addresses.last_name', 'addresses.address1', 'addresses.address2', 'addresses.postcode', 'addresses.city', 'addresses.state', 'addresses.country', 'addresses.email', 'addresses.phone', 'cart_items.total as price', 'cart_items.base_price as basePrice', 'cart_items.quantity as purchasedQuantity', 'orders.status','product_qty_size.size','order_status_for_single_product.status as deliveryStatus')
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('addresses.default_address', 1)
            ->where('products.type', 'simple')
            ->whereNULL('products.product_type')
            ->where('products.parent_id', $args['product_id'])
            ->groupBy('cart_items.ticket_id', 'cart_items.cart_id')
            ->orderBy('orders.id', 'desc')->get();

        if (!empty($merchantList)) {
            $arr_merchantList = $merchantList->map(function($item, $key) {
                return [
                    'Product Name' => $item->productName,
                    'Size' => $item->size,
                    'Price' => $item->basePrice,
                    'Order No' => "#".$item->order_id,
                    'Quantity' => $item->purchasedQuantity,
                    'Total' => $item->price,
                    'Delivery Status' => $item->status,
                    'Customer Name' => $item->first_name." ".$item->last_name,
                    'CustomerAddress' => $item->address1.",".$item->address2.",".$item->city.",".$item->state.",".$item->country.",".$item->postcode,
                    'Order Date' => $item->created_at->format('d/m/Y H:i A')
                ];
            });

            $responseData = $this->productRepository->downloadBookedEventMerchants($arr_merchantList,$owner->id);
            $response['url'] = $responseData['url'];

        } else {
            throw new Exception("As of now no Merchants available For said Events.");
        }
        return $response;
    }

    public function downloadEventTickets($rootValue, array $args, GraphQLContext $context)
    {
        $response = [];
        $owner = bagisto_graphql()->guard($this->guard)->user();

        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query();
        $eventList= $query
            ->leftJoin('booked_event_tickets_history', 'booked_event_tickets_history.product_id', '=', 'products.id')
            ->leftJoin('orders', 'booked_event_tickets_history.orderId', '=', 'orders.id')
            ->leftJoin('cart_items', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('booking_product_event_ticket_translations', 'booked_event_tickets_history.ticket_id', '=', 'booking_product_event_ticket_translations.booking_product_event_ticket_id')
            ->select('products.*','orders.customer_first_name as firstname', 'orders.customer_last_name as lastname', 'orders.customer_email as email', 'orders.created_at','cart_items.ticket_id', 'orders.id AS order_id', 'booking_product_event_ticket_translations.name as ticketType', 'cart_items.base_price as price','orders.status')
            ->selectRaw("CONCAT(customer_first_name, ' ', customer_last_name) as customer_name")
            ->whereIn('orders.status', ['completed', 'pending'])
            ->where('cart_items.product_id', $args['product_id'])
            ->where('booked_event_tickets_history.product_id', $args['product_id'])
            ->groupBy('booked_event_tickets_history.id')
            ->orderBy('booked_event_tickets_history.id','desc')->get();

        if (!empty($eventList)) {
            $arr_ticketList = $eventList->map(function($item, $key) {

                return [
                    'Product Name' => $item->sku,
                    'Ticket Type' => $item->ticketType,
                    'Price' => $item->price,
                    'Order No' => "#".$item->order_id,
                    'Customer Name' => $item->firstname." ".$item->lastname,
                    'Customer Email' => $item->firstname." ".$item->email,
                    'Order Date' => $item->created_at->format('d/m/Y H:i A')
                ];
            });

            $responseData = $this->productRepository->downloadBookedEventTickets($arr_ticketList,$owner->id);
            $response['url'] = $responseData['url'];

        } else {
            throw new Exception("No Tickets records for this event .");
        }
        return $response;
    }


    public function getBookedTicketListByCustomer($rootValue, array $args, GraphQLContext $context)
    {
        $owner = bagisto_graphql()->guard($this->guard)->user();
        $prefix = DB::getTablePrefix();
        $query = \Webkul\GraphQLAPI\Models\Catalog\Product::query()
            ->leftJoin('cart_items', 'products.id', '=', 'cart_items.product_id')
            ->leftJoin('orders', 'cart_items.cart_id', '=', 'orders.cart_id')
            ->leftJoin('addresses', 'orders.customer_email', '=', 'addresses.email')
            ->addSelect('products.id', 'products.sku as productName', 'orders.created_at', 'cart_items.ticket_id', 'orders.id AS order_id', 'addresses.address_type', 'addresses.first_name as firstname', 'addresses.last_name  as lastname', 'addresses.address1', 'addresses.address2', 'addresses.postcode', 'addresses.city', 'addresses.state', 'addresses.country', 'addresses.email', 'addresses.phone', 'cart_items.total as price', 'orders.status', 'cart_items.base_price as basePrice')
            ->selectRaw('SUM(' . $prefix . 'cart_items.quantity) as quantity')
            ->whereIn('orders.status', ['completed', 'pending'])
//            ->where('addresses.default_address', 1)
            ->where('products.type', 'booking')
            ->where('orders.customer_email', $owner->email)
            ->groupBy('cart_items.product_id', 'cart_items.cart_id')
            ->orderBy('orders.id', 'desc');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }
}
