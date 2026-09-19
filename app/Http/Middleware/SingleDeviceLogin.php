<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SingleDeviceLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            $sessionId = session()->getId();

            // Deactivation must take effect immediately. Checking only at login
            // let an already-signed-in user keep full access until their session
            // expired, which is exactly when access needs to stop.
            if (! $user->is_active) {
                return $this->endSession(
                    $request,
                    'Your account has been deactivated. Please contact the administrator.',
                );
            }

            if ($user->current_session_id && $user->current_session_id !== $sessionId) {
                return $this->endSession(
                    $request,
                    'Your session was terminated because you logged in from another device.',
                );
            }
        }

        return $next($request);
    }

    /**
     * End the session and send the user back to login — or a 401 for XHR, so a
     * background poll does not silently render the login page into a widget.
     *
     * Deliberately not named `terminate`: Laravel's Kernel treats that name as
     * the terminable-middleware hook and calls it after every response.
     */
    private function endSession(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('error', $message);
    }
}
