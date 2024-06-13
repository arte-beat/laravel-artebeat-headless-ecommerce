<?php
namespace Webkul\GraphQLAPI\Http\Middleware;
use Illuminate\Http\Request;
use Closure;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
class ActiveUserAuthMiddleware
{

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */

    public function handle(Request $request, Closure $next): Response
    {
        $authUser = auth('api')->user();

        if (!empty($authUser)  && $authUser->status == 0) {
            auth('api')->logout();
        }
        return $next($request);
    }
}