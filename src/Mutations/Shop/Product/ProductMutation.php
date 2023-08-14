<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Product;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Helpers\ProductType;
use Webkul\Product\Models\Product;
use Webkul\Core\Contracts\Validations\Slug;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductFlatRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\PromoterRepository;
use Webkul\Product\Repositories\ArtistRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Models\ProductAttributeValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductMutation extends Controller
{
    /**
     * @var int
     */
    protected $id;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Product\Repositories\ProductRepository  $productRepository
     * @param  \Webkul\Product\Repositories\ProductFlatRepository  $productFlatRepository
     * @param  \Webkul\Product\Repositories\ProductAttributeValueRepository $productAttributeValueRepository
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected PromoterRepository $promoterRepository,
        protected ArtistRepository $artistRepository,
        protected ProductFlatRepository $productFlatRepository,
        protected ProductAttributeValueRepository $productAttributeValueRepository
    ) {
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
        $query->where('type', 'booking');
        if(isset($args['input']['name'])) {
            $name = strtolower(str_replace(" ", "-", $args['input']['name']));
            $query->where('sku', 'like', '%' . urldecode($name) . '%');
        }
        $query->where('owner_type', 'customer');
        $owner = bagisto_graphql()->guard($this->guard)->user();
        if(!empty($owner))
            $query->where('owner_id', $owner->id);
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }

    public function similarEventFilter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();
        $query = $query
            ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
            ->addSelect('products.*')
            ->where('products.id', '!=', $args['input']['event_id'])
            ->where('products.type', '=', 'booking')
            ->where('booking_products.event_type', '=', $args['input']['event_type'])
            ->where('booking_products.available_from', '>=', date('Y-m-d'))
            ->orderBy('products.id', 'DESC');
        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;

        $similarProducts = $query->paginate($count,['*'],'page',$page);
        return $similarProducts;
    }

    public function particularEventFilter($rootValue, array $args, GraphQLContext $context)
    {
        $query = \Webkul\Product\Models\Product::query();

        if(!empty($args['input']['weekly_events'])) {

            $lastDayOfWeek = date('Y-m-d',strtotime('next Sunday'));
            $today = date('Y-m-d');
            $query->where('products.type', 'booking');
            if(isset($args['input']['name'])) {
                $name = strtolower(str_replace(" ", "-", $args['input']['name']));
                $query->where('products.sku', 'like', '%' . urldecode($name) . '%');
            }
            $query = $query->distinct()
                ->leftJoin('booking_products', 'products.id', '=', 'booking_products.product_id')
                ->addSelect('products.*')
                ->where('booking_products.available_from', '<=', $lastDayOfWeek)
                ->where('booking_products.available_from', '>=', $today);


        }
        else{
            $query->where('type', 'booking');
            if(isset($args['input']['name'])) {
                $name = strtolower(str_replace(" ", "-", $args['input']['name']));
                $query->where('sku', 'like', '%' . urldecode($name) . '%');
            }
            if(!empty($args['input']['is_feature_event'])) {
                $query->where('is_feature_event', '=', $args['input']['is_feature_event']);
            }
            if(!empty($args['input']['is_hero_event'])) {

                $query->where('is_hero_event', '=', $args['input']['is_hero_event']);
            }
        }

        $count = isset($args['first']) ? $args['first'] : 10;
        $page = isset($args['page']) ? $args['page'] : 1;
        return $query->paginate($count,['*'],'page',$page);
    }

    /**
     * Store the specified resource in storage.
     *
     * @param  int  $id
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
            'name'   => 'string|required',
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

            Event::dispatch('catalog.product.create.before');
            $product = $this->productRepository->create($data);
            Event::dispatch('catalog.product.create.after', $product);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        if(!empty($product)) {
            $id = $product->id;
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
        }else{
            throw new Exception("Unable to process at the moment. Please try again after sometime.");
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
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
            'name'   => 'string|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        $event = new Product();
        $eventdata = $event::where('sku', '=', $data['sku'])->where('id', '!=', $id)->first();

        if (!empty($eventdata)) {
            throw new Exception("{\"name\":[\"The name has already been taken.\"]}");
        }
        if(!empty($product)) {
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
        }else{
            throw new Exception("Unable to process at the moment. Please try again after sometime.");
        }
    }

    /**
     * Store the specified resource in storage.
     *
     * @param  int  $id
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
//            if($index === 1) {
            //echo "<pre>"; print_r($data);
            $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
            $validator = Validator::make($data, [
                'name'   => 'string|required',
            ]);

            if ($validator->fails()) {
                throw new Exception($validator->messages());
            }
            $product = $this->productRepository->findOrFail($data['product_id']);
            $event = new Product();
            $eventdata = $event::where('sku', '=', $data['sku'])->first();

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
                    Event::dispatch('catalog.product.update.after', $updateProduct[$index]);

                    if ($multipleFiles != null) {
                        $files = $multipleFiles[$index];
                        bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
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
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updateMerchantEventBooking($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }
        $multipleData = $args['input'];
        $multipleFiles = $args['files'];
        foreach ($multipleData as $index => $data) {
//            if($index === 1) {
            //echo "<pre>"; print_r($data);
            $product = $this->productRepository->findOrFail($data['id']);
            if (!empty($product)) {
                $id = $data['id'];
                try {
                    $data['sku'] = strtolower(str_replace(" ", "-", $data['name']));
                    $validator = Validator::make($data, [
                        'name'   => 'string|required',
                    ]);

                    if ($validator->fails()) {
                        throw new Exception($validator->messages());
                    }
                    $product = $this->productRepository->findOrFail($data['product_id']);

                    $event = new Product();
                    $eventdata = $event::where('sku', '=', $data['sku'])->where('id', '!=', $id)->first();

                    if (!empty($eventdata)) {
                        throw new Exception("{\"name\":[\"The name has already been taken.\"]}");
                    }
                    Event::dispatch('catalog.product.update.before', $id);
                    $updateProduct[$index] = $this->productRepository->update($data, $id);
                    Event::dispatch('catalog.product.update.after', $updateProduct[$index]);

                    if ($multipleFiles != null) {
                        $files = $multipleFiles[$index];
                        bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
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

    public function uploadEventImages($rootValue, array $args, GraphQLContext $context)
    {
        if (!isset($args['product_id']) || (isset($args['product_id']) && !$args['product_id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['product_id'];
        $product = $this->productRepository->findOrFail($id);
        if(!empty($product)) {
            if (isset($product->id)) {
                $files = $args['files'];
                if ($files != null) {
                    bagisto_graphql()->uploadEventImages($files, $product, 'product/', 'image');
                }
                return $product;
            }
        }else{
            throw new Exception("Unable to process at the moment. Please try again after sometime.");
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deleteEventBooking($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || ( isset($args['id']) && !$args['id'])) {
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
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || ( isset($args['id']) && !$args['id'])) {
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
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
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
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function updatePromoter($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
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
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deletePromoter($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $promoter = $this->promoterRepository->findOrFail($id);

        try {
            if($promoter){
                $this->promoterRepository->delete($id);
                return ['success' => trans('admin::app.response.delete-success', ['name' => 'Promoter'])];
            }
        } catch(\Exception $e) {
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
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
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

        if (count($artistIds) + count($artistNames) != count($artistTypes)){
            throw new Exception('Number of Artist types do not much number of Artists provided');
        }
        if (count($promoterIds) + count($promoterNames) != count($promoterTypes)){
            throw new Exception('Number of Promoter types do not much number of Promoters provided');
        }

        try {
            // Register Artists and promoters
            if(count($artistNames) != 0){
                $newArtists = $this->artistRepository->createArtistsByName($artistNames);
                foreach ($newArtists as $artist) {
                    array_push($artistIds, $artist->id);
                }
            }
            if(count($promoterNames) != 0){
                $newPromoters = $this->promoterRepository->createPromotersByName($promoterNames);
                forEach ($newPromoters as $promoter) {
                    array_push($promoterIds, $promoter->id);
                }
            }
            $event = $this->productRepository->syncEventPerformers($eventId, $artistIds, $promoterIds, $artistTypes, $promoterTypes);
            return $event;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
}
