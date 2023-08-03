<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Product;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Helpers\ProductType;
use Webkul\Core\Contracts\Validations\Slug;
use Webkul\Product\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductFlatRepository;
use Webkul\Product\Repositories\ProductRepository;
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
        protected ProductFlatRepository $productFlatRepository,
        protected ProductAttributeValueRepository $productAttributeValueRepository
    ) {
        $this->guard = 'api';
        auth()->setDefaultDriver($this->guard);
//        $this->_config = request('_config');
        $this->middleware('auth:' . $this->guard);
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
        $data['type'] = str_replace(" ", "-", 'booking');
        $data['attribute_family_id'] = 1;

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
        $data['type'] = str_replace(" ", "-", 'booking');
        $data['attribute_family_id'] = 1;

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
}
