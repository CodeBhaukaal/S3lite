<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Http\Request;
use App\Http\Response;

final class SecurityHeaders implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'X-XSS-Protection'        => '1; mode=block',
            'Permissions-Policy'      => 'geolocation=(), microphone=(), camera=()',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if (!$request->isApi()) {
            // Inline styles/scripts are used by the panel; no remote origins are allowed.
            $headers['Content-Security-Policy'] = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "media-src 'self' blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-src 'self' blob:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
        }

        if (Config::get('app.force_https', false)) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $response->withHeaders($headers);
    }
}
