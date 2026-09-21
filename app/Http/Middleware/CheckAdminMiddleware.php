<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Session;

class CheckAdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            $currentSessionId = Session::getId();

 
            if ($user->session_id && $user->session_id !== $currentSessionId) {
    
                Session::getHandler()->destroy($user->session_id);
            }

            $user->update(['session_id' => $currentSessionId]);

            $admin = User::findOrFail($user->id);

         
            return $next($request);

            return redirect('/login')->with('error', 'Please log in');
        }

        return redirect('/login')->with('error', 'Please log in');
    }
}
