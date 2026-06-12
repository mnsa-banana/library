<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyReadToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $this->extractBearer($request);

        if ($provided !== null) {
            foreach ($this->tokens() as $consumer => $token) {
                if (hash_equals($token, $provided)) {
                    $request->attributes->set('library_consumer', $consumer);
                    Log::info('library read', ['consumer' => $consumer, 'path' => $request->path()]);

                    return $next($request);
                }
            }
        }

        return response()->json(['error' => 'unauthorized'], 401);
    }

    /**
     * Parse LIBRARY_READ_TOKENS ("name:token,name:token") into [name => token].
     * Malformed pairs (no colon, empty name or token) are ignored.
     *
     * @return array<string, string>
     */
    private function tokens(): array
    {
        $tokens = [];

        foreach (explode(',', (string) config('library.read_tokens', '')) as $pair) {
            [$name, $token] = array_pad(explode(':', trim($pair), 2), 2, '');

            if ($name !== '' && $token !== '') {
                $tokens[$name] = $token;
            }
        }

        return $tokens;
    }

    private function extractBearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7) ?: null;
    }
}
