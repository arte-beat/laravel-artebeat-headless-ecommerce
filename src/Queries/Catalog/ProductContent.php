<?php

namespace Webkul\GraphQLAPI\Queries\Catalog;

use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Customer\Repositories\WishlistRepository;
use Webkul\GraphQLAPI\Queries\BaseFilter;
use Webkul\Product\Helpers\ConfigurableOption as ProductConfigurableHelper;
use Webkul\Product\Helpers\View as ProductViewHelper;
use Webkul\Product\Helpers\Review;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Models\Showcase;

class ProductContent extends BaseFilter
{
    /**
     * Contains route related configuration.
     *
     * @var array
     */
    protected $_config;

    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;
    
    /**
     * Product repository instance.
     *
     * @var \Webkul\Product\Repositories\ProductRepository
     */
    protected $productRepository;

    /**
     * Wishlist repository instance.
     *
     * @var \Webkul\Customer\Repositories\WishlistRepository
     */
    protected $wishlistRepository;

    /**
     * Product view helper instance.
     *
     * @var \Webkul\Product\Helpers\View
     */
    protected $productViewHelper;

    /**
     * Product Review helper instance.
     *
     * @var \Webkul\Product\Helpers\Review
     */
    protected $review;

    /**
     * Product configurable helper instance.
     *
     * @var \Webkul\Product\Helpers\ConfigurableOption
     */
    protected $productConfigurableHelper;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Product\Repositories\ProductRepository  $productRepository
     * @param  \Webkul\Customer\Repositories\WishlistRepository  $wishlistRepository
     * @param  \Webkul\Product\Helpers\View  $productViewHelper
     * @param  \Webkul\Product\Helpers\Review  $review
     * @param  \Webkul\Product\Helpers\ConfigurableOption  $productConfigurableHelper
     * @return void
     */
    public function __construct(
        ProductRepository $productRepository,
        WishlistRepository $wishlistRepository,
        ProductViewHelper $productViewHelper,
        Review $review,
        ProductConfigurableHelper $productConfigurableHelper
    ) {
        $this->guard = 'api';

        $this->productRepository = $productRepository;

        $this->wishlistRepository = $wishlistRepository;

        $this->productViewHelper = $productViewHelper;

        $this->review = $review;

        $this->productConfigurableHelper = $productConfigurableHelper;

        $this->_config = request('_config');
    }

    /**
     * Get additional data.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return mixed
     */
    public function getAdditionalData($rootValue, array $args, GraphQLContext $context)
    {
        return $this->productViewHelper->getAdditionalData($rootValue);
    }

    /**
     * Get related products.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return mixed
     */
    public function getRelatedProducts($rootValue, array $args, GraphQLContext $context)
    {
        $product = $this->productRepository->find($args['productId']);

        if ($product) {
            return $product->related_products()->whereIn('products.type', ['simple', 'virtual', 'configurable'])->get();
        }

        return null;
    }

    public function getShowcase($rootValue, array $args, GraphQLContext $context)
    {
        $showcase = Showcase::latest()->first();
        if ($showcase) {
            return $showcase;
        }
        return null;
    }

    /**
     * Get product price html.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return array
     */
    public function getProductPriceHtml($rootValue, array $args, GraphQLContext $context)
    {
        $priceArray = [
            'id'                         => $rootValue->id,
            'type'                       => $rootValue->type,
            'html'                       => strip_tags($rootValue->getTypeInstance()->getPriceHtml($rootValue)),
            'regular'                    => core()->currency($rootValue->getTypeInstance()->evaluatePrice($rootValue->price)),
            'regularWithoutCurrencyCode' => $rootValue->getTypeInstance()->evaluatePrice($rootValue->price),
            'special'                    => '',
            'specialWithoutCurrencyCode' => '',
            'currencyCode'               => core()->getCurrentCurrency()->code,
        ];

        switch ($rootValue->type) {
            case 'simple':
            case 'virtual':
            case 'downloadable':
                if ($rootValue->getTypeInstance()->haveSpecialPrice()) {
                    $priceArray['regular'] = core()->currency($rootValue->getTypeInstance()->evaluatePrice($rootValue->price));
                    $priceArray['regularWithoutCurrencyCode'] = $rootValue->getTypeInstance()->evaluatePrice($rootValue->price);
                    $priceArray['special'] = core()->currency($rootValue->getTypeInstance()->evaluatePrice($rootValue->getTypeInstance()->getSpecialPrice()));
                    $priceArray['specialWithoutCurrencyCode'] = $rootValue->getTypeInstance()->evaluatePrice($rootValue->getTypeInstance()->getSpecialPrice());
                }
                break;

            case 'configurable':
                $priceArray['regular'] = trans('shop::app.products.price-label') . ' ' . core()->currency($rootValue->getTypeInstance()->evaluatePrice($rootValue->getTypeInstance()->getMinimalPrice()));
                $priceArray['regularWithoutCurrencyCode'] = $rootValue->getTypeInstance()->evaluatePrice($rootValue->getTypeInstance()->getMinimalPrice());

                if ($rootValue->getTypeInstance()->haveOffer()) {
                    $priceArray['special'] = core()->currency($rootValue->getTypeInstance()->evaluatePrice($rootValue->getTypeInstance()->getOfferPrice()));
                    $priceArray['specialWithoutCurrencyCode'] = $rootValue->getTypeInstance()->evaluatePrice($rootValue->getTypeInstance()->getOfferPrice());
                }
                break;

            case 'grouped':
                $priceArray['regular'] = trans('shop::app.products.starting-at') . ' ' . core()->currency($rootValue->getTypeInstance()->getMinimalPrice());
                $priceArray['regularWithoutCurrencyCode'] = $rootValue->getTypeInstance()->getMinimalPrice();
                break;

            case 'bundle':
                $prices = $rootValue->getTypeInstance()->getProductPrices();
                $priceArray['regular'] = $priceArray['special'] = '';

                /**
                 * Not in use.
                 */
                $priceArray['regularWithoutCurrencyCode'] = $priceArray['specialWithoutCurrencyCode'] = '';

                if ($prices['from']['regular_price']['price'] != $prices['from']['final_price']['price']) {
                    $priceArray['regular'] .= $prices['from']['regular_price']['formated_price'];
                    $priceArray['special'] .= $prices['from']['final_price']['formated_price'];
                } else {
                    $priceArray['regular'] .= $prices['from']['regular_price']['formated_price'];
                }

                if ($prices['from']['regular_price']['price'] != $prices['to']['regular_price']['price']
                    || $prices['from']['final_price']['price'] != $prices['to']['final_price']['price']
                ) {
                    $priceArray['regular'] .= ' To ';

                    if ($prices['to']['regular_price']['price'] != $prices['to']['final_price']['price']) {
                        $priceArray['regular'] .= $prices['to']['regular_price']['formated_price'];
                        $priceArray['special'] .= $prices['to']['final_price']['formated_price'];
                    } else {
                        $priceArray['regular'] .= $prices['to']['regular_price']['formated_price'];
                    }
                }
                break;
        }

        return $priceArray;
    }

    /**
     * Check wishlist
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return bool
     */
    public function checkIsInWishlist($rootValue, array $args, GraphQLContext $context)
    {
        if ( bagisto_graphql()->guard($this->guard)->check() ) {
            $customer = bagisto_graphql()->guard($this->guard)->user();

            $wishlist = $this->wishlistRepository->findOneWhere([
                'customer_id'   => $customer->id,
                'product_id'    => $rootValue->id
            ]);

            if ($wishlist) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get configurable data.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return mixed
     */
    public function getConfigurableData($rootValue, array $args, GraphQLContext $context)
    {
        $data = $this->productConfigurableHelper->getConfigurationConfig($this->productRepository->find($rootValue->id));

        $index = [];
        foreach ($data['index'] as $key => $attributeOptionsIds) {
            if (! isset($index[$key])) {
                $index[$key] = [
                    'id'                 => $key,
                    'attributeOptionIds' => [],
                ];
            }

            foreach ($attributeOptionsIds as $attributeId => $optionId) {
                if ($optionId) {
                    $optionData = [
                        'attributeId'       => $attributeId,
                        'attributeCode'     => '',
                        'attributeOptionId' => $optionId,
                    ];
                    
                    foreach ($data['attributes'] as $attribute) {
                        if ($attribute['id'] == $attributeId) {
                            $optionData['attributeCode'] = $attribute['code'];
                            break;
                        }
                    }

                    $index[$key]['attributeOptionIds'][] = $optionData;
                }
            }
        }
        $data['index'] = $index;

        $variant_prices = [];
        foreach ($data['variant_prices'] as $key => $prices) {
            $variant_prices[$key] = [
                'id'            => $key,
                'regular_price' => $prices['regular_price'],
                'final_price'   => $prices['final_price'],
            ];
        }
        $data['variant_prices'] = $variant_prices;

        $variant_images = [];
        foreach ($data['variant_images'] as $key => $imgs) {
            $variant_images[$key] = [
                'id'     => $key,
                'images' => [],
            ];

            foreach ($imgs as $img_index => $urls) {
                $variant_images[$key]['images'][$img_index] = $urls;
            }
        }
        $data['variant_images'] = $variant_images;

        $variant_videos = [];
        foreach ($data['variant_videos'] as $key => $imgs) {
            $variant_videos[$key] = [
                'id'     => $key,
                'videos' => [],
            ];

            foreach ($imgs as $img_index => $urls) {
                $variant_videos[$key]['videos'][$img_index] = $urls;
            }
        }
        $data['variant_videos'] = $variant_videos;

        return $data;
    }

    /**
     * Get cached gallery images.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return mixed
     */
    public function getCacheGalleryImages($rootValue, array $args, GraphQLContext $context)
    {
        return productimage()->getGalleryImages($rootValue);
    }

    /**
     * Get product's review list.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return mixed
     */
    public function getReviews($rootValue, array $args, GraphQLContext $context)
    {
        $product = $this->productRepository->find($rootValue->id);

        if ($product) {
            return $product->reviews->where('status', 'approved');
        }

        return [];
    }

    /**
     * Get product base image.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return mixed
     */
    public function getProductBaseImage($rootValue, array $args, GraphQLContext $context)
    {
        return productimage()->getProductBaseImage($rootValue);
    }

    /**
     * Get product avarage rating.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return string
     */
    public function getAverageRating($rootValue, array $args, GraphQLContext $context)
    {
        return $this->review->getAverageRating($rootValue);
    }

    /**
     * Get product percentage rating.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return array
     */
    public function getPercentageRating($rootValue, array $args, GraphQLContext $context)
    {
        return $this->review->getPercentageRating($rootValue);
    }

    /**
     * Get product share URL.
     *
     * @param  mixed  $rootValue
     * @param  array  $args
     * @param  GraphQLContext  $context
     * @return mixed
     */
    public function getProductShareUrl($rootValue, array $args, GraphQLContext $context)
    {
        return route('shop.productOrCategory.index', $rootValue->url_key);
    }
}
