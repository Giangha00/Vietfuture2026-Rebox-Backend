<?php

namespace App\Support;

class PublicBaseUrl
{
    public static function current(): string
    {
        return rtrim((string) config('rebox.public_base_url', config('app.url')), '/');
    }

    /**
     * Rewrite localhost media URLs to the host the client actually used (LAN IP).
     */
    public static function rewrite(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $request = request();
        if ($request === null) {
            return $url;
        }

        $host = $request->getHost();
        if (! is_string($host) || $host === '' || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $url;
        }

        $origin = rtrim($request->getSchemeAndHttpHost(), '/');
        $rewritten = preg_replace(
            '#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?#i',
            $origin,
            $url,
            1
        );

        return is_string($rewritten) ? $rewritten : $url;
    }

    /**
     * @param  list<mixed>|mixed  $urls
     * @return list<mixed>
     */
    public static function rewriteList(mixed $urls): array
    {
        if (! is_array($urls)) {
            return [];
        }

        return array_values(array_map(
            fn ($url) => is_string($url) ? self::rewrite($url) : $url,
            $urls
        ));
    }
}
