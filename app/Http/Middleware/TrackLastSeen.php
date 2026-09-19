<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Stamps users.last_seen_at on every authenticated request, at most once a
 * minute. "Online" for lead assignment means seen within the last few
 * minutes; the dialer's status poll keeps an open tab counted as present.
 */
class TrackLastSeen
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute()))) {
            User::whereKey($user->id)->update(['last_seen_at' => now()]);
            $user->setAttribute('last_seen_at', now());
        }

        return $next($request);
    }
}
