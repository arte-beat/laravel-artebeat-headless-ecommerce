<?php

namespace Webkul\GraphQLAPI\Mutations\Catalog;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rule;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductImage;
use Webkul\Product\Models\ProductFlat;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;
use Webkul\Product\Helpers\ProductType;
use Webkul\Core\Contracts\Validations\Slug;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductFlatRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ArtistRepository;
use Webkul\Product\Repositories\ShowcaseRepository;
use Webkul\Product\Repositories\PromoterRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Customer\Repositories\CustomerRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Events\SendMailForEventCreate;
use Illuminate\Support\Str;

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
     * @param \Webkul\Product\Repositories\ArtistRepository $artistRepository
     * @param \Webkul\Product\Repositories\ShowcaseRepository $showcaseRepository
     * @param \Webkul\Product\Repositories\PromoterRepository $promoterRepository
     * @param \Webkul\Product\Repositories\ProductFlatRepository $productFlatRepository
     * @param \Webkul\Product\Repositories\ProductAttributeValueRepository $productAttributeValueRepository
     * @param \Webkul\Customer\Repositories\CustomerRepository $customerRepository ;
     * @return void
     */
    public function __construct(
        protected ProductRepository               $productRepository,
        protected ArtistRepository                $artistRepository,
        protected ShowcaseRepository              $showcaseRepository,
        protected PromoterRepository              $promoterRepository,
        protected ProductFlatRepository           $productFlatRepository,
        protected ProductAttributeValueRepository $productAttributeValueRepository,
        protected CustomerRepository    $customerRepository
    )
    {
        $this->guard = 'admin-api';
        auth()->setDefaultDriver($this->guard);
        $this->_config = request('_config');
    }

    function calculateDistance($clientLatitude, $clientLongitude, $eventLatitude, $eventLongitude) {
        $earthRadius = 6371; // Radius of the Earth in kilometers

        $lat1 = deg2rad($clientLatitude);
        $lon1 = deg2rad($clientLongitude);
        $lat2 = deg2rad($eventLatitude);
        $lon2 = deg2rad($eventLongitude);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) + cos($lat1) * cos($lat2) * sin($deltaLon / 2) * sin($deltaLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c;

        return $distance;
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

        if(isset($args['input']['event_status'])) {
            $query->where('event_status', '=', $args['input']['event_status']);
        }

        if(isset($args['input']['search_text'])) {
            // Match artist, promoter, location and event name 
            $searchedName = strtolower(str_replace(" ", "-", $args['input']['search_text']));
            $query->where('sku', 'like', '%'. urldecode($searchedName). '%');

            $query->orWhereHas('promoters', function ($promoterQuery) use ($args) {
                    $promoterQuery->where('promoter_name', 'like', '%'.  $args['input']['search_text']. '%');
                });

            $query->orWhereHas('artists', function ($artistQuery) use ($args) {
                    $artistQuery->where('artist_name', 'like', '%'.  $args['input']['search_text']. '%');
                });
        }

        
        $query->whereHas('booking_product', function ($bookingQuery) use ($args, $query) {
            
            if(isset($args['input']['event_category'])) {
                $bookingQuery->where('event_type', $args['input']['event_category']);
            }

            if(isset($args['input']['weekly_events'])) {
                $bookingQuery->where('booking_products.available_every_week', '=', 1);
            }

            if(isset($args['input']['ticket_price_min']) && isset($args['input']['ticket_price_max'])) {
                $bookingQuery->whereHas('event_tickets', function ($eventTicketQuery) use ($args) {
                    $eventTicketQuery->where('price', '>=', $args['input']['ticket_price_min']);
                    $eventTicketQuery->where('price', '<=', $args['input']['ticket_price_max']);
                });
            }
        
            if(isset($args['input']['distance_min']) && isset($args['input']['distance_max']) && isset($args['input']['client_location_longitude']) && isset($args['input']['client_location_latitude'])) {

                $clientLongitude = $args['input']['client_location_longitude'];
                $clientLatitude = $args['input']['client_location_latitude'];
                $minDistance = $args['input']['distance_min'];
                $maxDistance = $args['input']['distance_max'];
                        
                // * 6371000 for meters, 6371 for kilometer and 3956 for miles
                $bookingQuery->selectRaw("(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance_from_client", [$clientLatitude, $clientLongitude, $clientLatitude]);
        
                $bookingQuery->having('distance_from_client', '>', $minDistance);
                $bookingQuery->having('distance_from_client', '<=', $maxDistance);
            }
        });  

        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function changeStatusOfEvent($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $event = $this->productRepository->findOrFail($id);


        try {
            if ($event != Null) {
                $customer = $this->customerRepository->findOrFail($event->owner_id);
                $owner = bagisto_graphql()->guard($this->guard)->user();
                $changeStatusOfEventparams['event_status_modified_on'] = date('Y-m-d H:i:s');
                $changeStatusOfEventparams['event_status'] = $args['event_status'];
                $owner_id = null;
                if(!empty($owner))
                    $owner_id = $owner->id;

                 Product::where('id', $id)->update(['event_status_modified_on' => date('Y-m-d H:i:s'), "event_status" => $args['event_status'], "event_status_changed_by" => $owner_id]);
                SendMailForEventCreate::dispatch($customer, $customer['email'],$args['event_status']);
                return [
                    'status' => true,
                    'message' => "Status changed successfully."
                ];
            } else {

                return [
                    'status' => false,
                    'message' => "Change status of event is failed. Please try again."
                ];
            }
        } catch (Exception $e) {

            throw new Exception($e->getMessage());
        }
    }


    public function merchFilter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();
        $query->where('type', 'simple');
        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
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
    public function collectionFilter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();
        $query->where('type', 'simple');
        $query->where('product_type', 'showcase');
        $query->where('parent_id', NULL);
        if (isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }
//        $query->orderBy('id', 'desc');

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count, ['*'], 'page', $page);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];

        if (
            ProductType::hasVariants($data['type'])
            && (!isset($data['super_attributes'])
                || !count($data['super_attributes']))
        ) {
            throw new Exception(trans('admin::app.catalog.products.configurable-error'));
        }

        if (isset($data['super_attributes']) && $data['super_attributes']) {
            $super_attributes = [];
            foreach ($data['super_attributes'] as $key => $super_attribute) {
                if (isset($super_attribute['attribute_code']) && isset($super_attribute['values']) && is_array($super_attribute['values'])) {
                    $super_attributes[$super_attribute['attribute_code']] = $super_attribute['values'];
                }
            }
            $data['super_attributes'] = $super_attributes;
        }

        $validator = Validator::make($data, [
            'type' => 'required',
            'attribute_family_id' => 'required',
            'sku' => ['required', 'unique:products,sku', new Slug],
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            Event::dispatch('catalog.product.create.before');
            $product = $this->productRepository->create($data);
            Event::dispatch('catalog.product.create.after', $product);
            return $product;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
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

//        $validator = Validator::make($data, [
//            'name'   => 'string|required',
//        ]);

        $validator = Validator::make($data, [
            'sku' => ['required', 'unique:products,sku', new Slug],
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

//        $event = new Product();
//        $eventdata = $event::where('sku', '=', $data['sku'])->first();
//
//        if (!empty($eventdata)) {
//            throw new Exception("{\"name\":[\"The name has already been taken.\"]}");
//        }

        $data['type'] = 'booking';
        $data['attribute_family_id'] = 1;
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
            // Only in case of booking product type
            if (isset($product->type) && $product->type == 'booking' && isset($data['booking']) && $data['booking']) {
                $data['booking']['event_pwd'] = bcrypt(Str::random(10));
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

    public function storeShowCase($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $showcase = $this->showcaseRepository->get();

        if (count($showcase) === 0) {
            try {
                $data = $args['input'];
                $file = isset($args['image']) ? $args['image'] : null;
                $header_image = isset($args['header_image']) ? $args['header_image'] : null;
                $section_file = isset($args['section_file']) ? $args['section_file'] : null;

                $validator = Validator::make($data, [
                    'title' => 'string|required',
                    'introduction' => 'string|required',
                    'description' => 'string|required',
                ]);

                if ($validator->fails()) {
                    throw new Exception($validator->messages());
                }

                try {
                    if ($file != null) {
                        $showcaseImgNameForDB = basename($file) . '.' . $file->getClientOriginalExtension();
                        Storage::disk('showcase')->put($showcaseImgNameForDB, $file->getContent());
                        $data['image'] = $showcaseImgNameForDB;
                    }
                    if ($header_image != null) {
                        $showcaseHeaderImgNameForDB = basename($header_image) . '.' . $header_image->getClientOriginalExtension();
                        Storage::disk('showcase')->put($showcaseHeaderImgNameForDB, $header_image->getContent());
                        $data['header_image'] = $showcaseHeaderImgNameForDB;
                    }
                    if ($section_file != null) {
                        $showcaseSectionFileNameForDB = basename($section_file) . '.' . $section_file->getClientOriginalExtension();
                        Storage::disk('showcase')->put($showcaseSectionFileNameForDB, $section_file->getContent());
                        $data['section_file'] = $showcaseSectionFileNameForDB;
                    }
                    $showcase = $this->showcaseRepository->create($data);
                    return $showcase;
                } catch (Exception $e) {
                    throw new Exception($e->getMessage());
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        } else {
            throw new Exception("Showcase is  already available can't create another !!!");
        }

    }

    public function updateShowCase($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['input']['id'];

        $showcase = $this->showcaseRepository->findOrFail($id);

        $file = isset($args['image']) ? $args['image'] : null;
        $header_image = isset($args['header_image']) ? $args['header_image'] : null;
        $section_file = isset($args['section_file']) ? $args['section_file'] : null;

        $validator = Validator::make($data, [
            'title' => 'string|required',
            'introduction' => 'string|required',
            'description' => 'string|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        if (!empty($showcase)) {
            try {
                if ($file != null) {
                    $showcaseImgNameForDB = basename($file) . '.' . $file->getClientOriginalExtension();
                    Storage::disk('showcase')->put($showcaseImgNameForDB, $file->getContent());
                    $data['image'] = $showcaseImgNameForDB;
                }
                if ($header_image != null) {
                    $showcaseHeaderImgNameForDB = basename($header_image) . '.' . $header_image->getClientOriginalExtension();
                    Storage::disk('showcase')->put($showcaseHeaderImgNameForDB, $header_image->getContent());
                    $data['header_image'] = $showcaseHeaderImgNameForDB;
                }
                if ($section_file != null) {
                    $showcaseSectionFileNameForDB = basename($section_file) . '.' . $section_file->getClientOriginalExtension();
                    Storage::disk('showcase')->put($showcaseSectionFileNameForDB, $section_file->getContent());
                    $data['section_file'] = $showcaseSectionFileNameForDB;
                }
                $showcase = $this->showcaseRepository->update($data, $id);
                return $showcase;
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
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
    public function deleteShowcase($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $showcase = $this->showcaseRepository->findOrFail($id);

        try {
            $this->showcaseRepository->delete($id);
            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Showcase'])];
        } catch (\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Showcase']));
        }
    }

    public function storeShowcaseCollection($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $multipleData = $args['input'];
        $multipleFiles = $args['files'];
        foreach ($multipleData as $index => $data) {
            $showcase = $this->showcaseRepository->findOrFail($data['showcase_id']);
            if (!empty($showcase)) {
                $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
                $validator = Validator::make($data, [
                    'sku' => ['required', 'unique:products,sku', new Slug],
                ]);

                if ($validator->fails()) {
                    throw new Exception($validator->messages());
                }
                $data['type'] = 'simple';
                $data['product_type'] = 'showcase';
                $data['attribute_family_id'] = 1;
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
                        Event::dispatch('catalog.product.update.after', $updateProduct[$index]);

                        if ($multipleFiles != null) {
                            if (isset($multipleFiles[$index])) {
                                $files = $multipleFiles[$index];
                                bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
                            }
                        }

                        $this->productRepository->syncQuantities($id, $data['quantity']);
                        $this->productRepository->syncCollectionWithProduct($id, $data['showcase_id']);
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

    public function updateShowcaseCollection($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $multipleData = $args['input'];
        $multipleFiles = $args['files'];
        $multipleDeleteData = $args['deleteInput'];
        foreach ($multipleDeleteData as $deleteData) {
            Product::where("id", "=", $deleteData['id'])->delete();
            ProductImage::where("product_id", "=", $deleteData['id'])->delete();
        }
        foreach ($multipleData as $index => $data) {
            if (isset($data['showcase_id']) && empty($data['product_id'])) {
                $showcase = $this->showcaseRepository->findOrFail($data['showcase_id']);
                if (!empty($showcase)) {
                    $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
                    $validator = Validator::make($data, [
                        'sku' => ['required', 'unique:products,sku', new Slug],
                    ]);

                    if ($validator->fails()) {
                        throw new Exception($validator->messages());
                    }
                    $data['type'] = 'simple';
                    $data['attribute_family_id'] = 1;
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
                            Event::dispatch('catalog.product.update.after', $updateProduct[$index]);

                            if ($multipleFiles != null) {
                                if (isset($multipleFiles[$index])) {
                                    $files = $multipleFiles[$index];
                                    bagisto_graphql()->uploadMerchantImages($files, $product, 'product/', 'image');
                                }
                            }

                            $this->productRepository->syncQuantities($id, $data['quantity']);
                            $this->productRepository->syncCollectionWithProduct($id, $data['showcase_id']);
                        } catch (Exception $e) {
                            throw new Exception($e->getMessage());
                        }
                    } else {
                        throw new Exception("Unable to process at the moment. Please try again after sometime.");
                    }
                }
            } else {
                $product = $this->productRepository->findOrFail($data['product_id']);
                $showcase = $this->showcaseRepository->findOrFail($data['showcase_id']);
                if (!empty($product) && !empty($showcase)) {
                    $productId = $data['product_id'];
                    try {
                        $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
                        $validator = Validator::make($data, [
                            'sku' => ['required', 'unique:products,sku,' . $productId, new Slug],
                        ]);

                        if ($validator->fails()) {
                            throw new Exception($validator->messages());
                        }
                        Event::dispatch('catalog.product.update.before', $productId);
                        $updateProduct[$index] = $this->productRepository->update($data, $productId);
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
                                bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
                            }
                        }
                        $this->productRepository->syncQuantities($productId, $data['quantity']);
                        $this->productRepository->syncCollectionWithProduct($productId, $data['showcase_id']);
                    } catch (Exception $e) {
                        throw new Exception($e->getMessage());
                    }
                } else {
                    throw new Exception("Unable to process at the moment. Please try again after sometime.");
                }
//                dd($updateProduct);
            }
        }
//        dd($updateProduct);
        return $updateProduct;
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

//        $validator = Validator::make($data, [
//            'name'   => 'string|required',
//        ]);

        $validator = Validator::make($data, [
            'sku' => ['required', 'unique:products,sku,' . $id, new Slug],
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

//        $event = new Product();
//        $eventdata = $event::where('sku', '=', $data['sku'])->where('id', '!=', $id)->first();
//
//        if (!empty($eventdata)) {
//            throw new Exception("{\"name\":[\"The name has already been taken.\"]}");
//        }

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
     * Update the specified resource in storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];

        $product = $this->productRepository->findOrFail($id);

        // Only in case of configurable product type
        if (isset($product->type) && $product->type == 'configurable' && isset($data['variants']) && $data['variants']) {
            $data['variants'] = bagisto_graphql()->manageConfigurableRequest($data);
        }

        // Only in case of grouped product type
        if (isset($product->type) && $product->type == 'grouped' && isset($data['links']) && $data['links']) {

            if (isset($data['links'])) {
                foreach ($data['links'] as $linkProduct) {
                    $productLink = $this->productRepository->findOrFail($linkProduct['associated_product_id']);
                    if ($productLink && $productLink->type != 'simple') {
                        throw new Exception("$productLink->type is not a added to Grouped product");
                    }
                }
            }

            $data['links'] = bagisto_graphql()->manageGroupedRequest($product, $data);
        }

        // Only in case of downloadable product type
        if (isset($product->type) && $product->type == 'downloadable') {
            if (isset($data['downloadable_links']) && $data['downloadable_links']) {
                $data['downloadable_links'] = bagisto_graphql()->manageDownloadableLinksRequest($product, $data);
            }

            if (isset($data['downloadable_samples']) && $data['downloadable_samples']) {
                $data['downloadable_samples'] = bagisto_graphql()->manageDownloadableSamplesRequest($product, $data);
            }
        }

        // Only in case of bundle product type
        if (isset($product->type) && $product->type == 'bundle' && isset($data['bundle_options']) && $data['bundle_options']) {

            if (isset($data['bundle_options'])) {
                foreach ($data['bundle_options'] as $bundleProduct) {
                    foreach ($bundleProduct['products'] as $prod) {
                        $productLink = $this->productRepository->findOrFail($prod['product_id']);
                        if ($productLink && $productLink->type != 'simple') {
                            throw new Exception("$productLink->type is not a added to Bundle product");
                        }
                    }
                }
            }

            $data['bundle_options'] = bagisto_graphql()->manageBundleRequest($product, $data);
        }

        // Only in case of booking product type
        if (isset($product->type) && $product->type == 'booking' && isset($data['booking']) && $data['booking']) {
            $data['booking'] = bagisto_graphql()->manageBookingRequest($data['booking']);
        }

        // Only in case of customer group price
        if (isset($data['customer_group_prices']) && $data['customer_group_prices']) {
            $data['customer_group_prices'] = bagisto_graphql()->manageCustomerGroupPrices($product, $data);
        }

        $validator = $this->validateFormData($id, $data);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $multiselectAttributeCodes = array();

        foreach ($product->attribute_family->attribute_groups as $attributeGroup) {
            $customAttributes = $product->getEditableAttributes($attributeGroup);

            if (count($customAttributes)) {
                foreach ($customAttributes as $attribute) {
                    if ($attribute->type == 'multiselect') {
                        array_push($multiselectAttributeCodes, $attribute->code);
                    }
                }
            }
        }

        if (count($multiselectAttributeCodes)) {
            foreach ($multiselectAttributeCodes as $multiselectAttributeCode) {
                if (!isset($data[$multiselectAttributeCode])) {
                    $data[$multiselectAttributeCode] = array();
                }
            }
        }

        $image_urls = $video_urls = [];
        if (isset($data['images'])) {
            $image_urls = $data['images'];
            unset($data['images']);
        }
        if (isset($data['videos'])) {
            $video_urls = $data['videos'];
            unset($data['videos']);
        }

        $inventories = [];
        if (isset($data['inventories'])) {
            foreach ($data['inventories'] as $key => $inventory) {
                if (isset($inventory['inventory_source_id']) && isset($inventory['qty'])) {
                    $inventories[$inventory['inventory_source_id']] = $inventory['qty'];
                }
            }
            $data['inventories'] = $inventories;
        }

        try {
            Event::dispatch('catalog.product.update.before', $id);

            $product = $this->productRepository->update($data, $id);

            Event::dispatch('catalog.product.update.after', $product);

            if (isset($product->id)) {
                bagisto_graphql()->uploadProductImages($product, $image_urls, 'product/', 'image');
                bagisto_graphql()->uploadProductImages($product, $video_urls, 'product/', 'video');

                if ($product->type == 'booking') {
                    $allow_types = ['appointment', 'rental', 'table'];
                    $booking = $product->booking_product()->first();

                    if (isset($booking->type) && in_array($booking->type, $allow_types)) {
                        $same_slots = [];
                        $different_slots = [];
                        $booking_slots = [];

                        switch ($booking->type) {
                            case 'appointment':
                                $booking_slots = $booking->appointment_slot()->first();
                                break;
                            case 'rental':
                                $booking_slots = $booking->rental_slot()->first();
                                break;
                            case 'table':
                                $booking_slots = $booking->table_slot()->first();
                                break;

                            default:
                                $booking_slots = [];
                                break;
                        }

                        foreach ($booking_slots->slots as $day => $slot) {
                            if ($booking_slots->same_slot_all_days == 0) {
                                foreach ($slot as $timing) {
                                    $timing['day'] = $day;
                                    $different_slots[] = $timing;
                                }
                            } else {
                                $same_slots[] = $slot;
                            }
                        }

                        $product->same_day_slots = $same_slots;
                        $product->different_day_slots = $different_slots;
                    }
                }

                return $product;
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function validateFormData($id, $data)
    {
        $this->id = $id;
        $product = $this->productRepository->findOrFail($this->id);

        $validateRules =
            array_merge($product->getTypeInstance()->getTypeValidationRules(), [
                'sku' => ['required', 'unique:products,sku,' . $this->id, new \Webkul\Core\Contracts\Validations\Slug],
                // 'images.*'           => 'nullable|mimes:jpeg,jpg,bmp,png',
                'special_price_from' => 'nullable|date',
                'special_price_to' => 'nullable|date|after_or_equal:special_price_from',
                'special_price' => ['nullable', new \Webkul\Core\Contracts\Validations\Decimal, 'lt:price'],
            ]);

        foreach ($product->getEditableAttributes() as $attribute) {
            if ($attribute->code == 'sku' || $attribute->type == 'boolean') {
                continue;
            }

            $validations = [];

            if (!isset($validateRules[$attribute->code])) {
                array_push($validations, $attribute->is_required ? 'required' : 'nullable');
            } else {
                $validations = $validateRules[$attribute->code];
            }

            if ($attribute->type == 'text' && $attribute->validation) {
                array_push(
                    $validations,
                    $attribute->validation == 'decimal'
                        ? new \Webkul\Core\Contracts\Validations\Decimal
                        : $attribute->validation
                );
            }

            if ($attribute->type == 'price') {
                array_push($validations, new \Webkul\Core\Contracts\Validations\Decimal);
            }

            if ($attribute->is_unique) {
                array_push($validations, function ($field, $value, $fail) use ($attribute) {
                    $column = ProductAttributeValue::$attributeTypeFields[$attribute->type];

                    if (!$this->productAttributeValueRepository->isValueUnique($this->id, $attribute->id, $column, request($attribute->code))) {
                        $fail('The :attribute has already been taken.');
                    }
                });
            }

            $validateRules[$attribute->code] = $validations;
        }

        return Validator::make($data, $validateRules);
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

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateBulkEventType($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        //

        if (isset($args['input']['is_hero_event'])) {

            if (!empty($args['input']['id'])) {
                $herobanner = $this->productRepository->where('is_hero_event', 1)->where('type', 'booking')->get();
                $total = count($herobanner) + count($args['input']['id']) - count($args['input']['removeId']);

                if ($total <= 5) {
                    try {
                        if ($args['input']['is_hero_event'] == 1) {
                            $data['is_hero_event'] = $args['input']['is_hero_event'];
                            $id = $args['input']['id'];
                        }
                    } catch (Exception $e) {
                        throw new Exception($e->getMessage());
                    }
                } else {
                    throw new Exception('Maximum 5 events are allowed at a time for hero event.');
                }

            }
            if (isset($args['input']['removeId'])) {
                $removeid = $args['input']['removeId'];
                $removedata['is_hero_event'] = 0;
            }

        }
        if (isset($args['input']['is_feature_event'])) {

            try {

                if (isset($args['input']['id']) && $args['input']['is_feature_event'] == 1) {
                    $data['is_feature_event'] = $args['input']['is_feature_event'];
                    $id = $args['input']['id'];
                }
                if (isset($args['input']['removeId'])) {
                    $removeid = $args['input']['removeId'];
                    $removedata['is_feature_event'] = 0;
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }

        }
        if (!empty($args['input']['is_hero_event']) || !empty($args['input']['is_feature_event'])) {
            // Only in case of booking product type
            try {
                if (!empty($id)) {
                    $updateProduct = $this->productRepository->whereIn('id', $id)->update($data);
                }
                if (!empty($removeid)) {
                    $removeupdateProduct = $this->productRepository->whereIn('id', $removeid)->update($removedata);
                }

                return ['success' => trans('admin::app.response.update-success', ['name' => 'Event'])];

            } catch (Exception $e) {
                throw new Exception(trans('admin::app.response.update-failed', ['name' => 'Event']));
            }
        } else {
            throw new Exception("Please select an event to update.");
        }
    }
}
