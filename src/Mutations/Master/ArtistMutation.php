<?php

namespace Webkul\GraphQLAPI\Mutations\Master;

use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\ArtistRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ArtistMutation extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Product\Repositories\ArtistRepository  $artistRepository
     * @return void
     */
    public function __construct(
        protected ArtistRepository $artistRepository,
    )
    {
        $this->guard = 'admin-api';
        auth()->setDefaultDriver($this->guard);
        $this->_config = request('_config');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $file = isset($args['file']) ? $args['file']  : null;

        $validator = Validator::make($data, [
            'artist_name'   => 'string|required',
            // 'artist_type'   => 'numeric|required',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            if ($file != null) {
                $imgNameForDB = basename($file). '.' . $file->getClientOriginalExtension();
                Storage::disk('artist')->put($imgNameForDB, $file->getContent());
                $data['artist_image'] = $imgNameForDB;
            }
            $artist = $this->artistRepository->create($data);
//            $artist['artist_image'] = Storage::disk('artist')->url($artist['artist_image']);
            return $artist;
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
    public function update($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || !isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        $id = $args['id'];

        $file = isset($args['file']) ? $args['file']  : null;

        $validator = Validator::make($data, [
            'artist_name'   => 'string|required',
            // 'artist_type'   => 'numeric|required',
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            if ($file != null) {
                $imgNameForDB = basename($file). '.' . $file->getClientOriginalExtension();
                Storage::disk('artist')->put($imgNameForDB, $file->getContent());
                $data['artist_image'] = $imgNameForDB;
            }
            $artist = $this->artistRepository->update($data, $id);
            /*dd(Storage::disk('artist'));
            $artist['artist_image'] = Storage::disk('artist')->url($artist['artist_image']);*/
            return $artist;
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
    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $id = $args['id'];
        $artist = $this->artistRepository->findOrFail($id);

        try {
            $this->artistRepository->delete($id);
            return ['success' => trans('admin::app.response.delete-success', ['name' => 'Artist'])];
        } catch(\Exception $e) {
            throw new Exception(trans('admin::app.response.delete-failed', ['name' => 'Artist']));
        }
    }
}
