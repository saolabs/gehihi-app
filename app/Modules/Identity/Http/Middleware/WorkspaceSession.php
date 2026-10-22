<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Repositories\IdentityRepositoryInterface;
use Closure;
use Illuminate\Http\Request;

class WorkspaceSession
{
    public function __construct(private IdentityRepositoryInterface $identities) {}

    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            $workspace = $this->identities->account($request->user()->id);
        } else {
            $token = $request->session()->get('guest_token');
            if (! $token) {
                $token = bin2hex(random_bytes(32));
                $request->session()->put('guest_token', $token);
            }
            $workspace = $this->identities->guest(hash('sha256', $token));
            if ($workspace->expires_at->isPast()) {
                $token = bin2hex(random_bytes(32));
                $request->session()->put('guest_token', $token);
                $workspace = $this->identities->guest(hash('sha256', $token));
            }
            $request->session()->put('guest_workspace_id', $workspace->id);
        }
        $request->attributes->set('workspace', $workspace);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
