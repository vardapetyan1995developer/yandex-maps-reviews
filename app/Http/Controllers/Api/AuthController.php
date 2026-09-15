<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SPA authentication via Sanctum.
 *
 * Cookie mode is used rather than issued tokens: a token has to be stored
 * somewhere on the frontend, and every such store (localStorage above all) is
 * readable by injected script under XSS. An HttpOnly session cookie has no such
 * weakness, and CSRF is covered by Sanctum's own mechanism.
 */
final class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        // Only requests Sanctum recognises as first-party (Origin is in the
        // stateful-domain list) carry a session. The check ensures a request
        // from another context gets a sensible answer rather than a 500.
        if ($request->hasSession()) {
            // Mandatory after a successful login: otherwise a session observed
            // before authentication stays valid (session fixation)
            $request->session()->regenerate();
        }

        return response()->json([
            'data' => $this->userPayload($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Сессия завершена.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }
}
