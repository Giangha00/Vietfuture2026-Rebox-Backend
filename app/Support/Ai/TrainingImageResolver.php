<?php

namespace App\Support\Ai;

/**
 * Resolve public upload URLs / relative refs to local filesystem paths for export.
 */
class TrainingImageResolver
{
    /**
     * @param  list<string>|mixed  $urls
     * @return list<array{url: string, local: ?string}>
     */
    public function resolveMany(mixed $urls): array
    {
        if (! is_array($urls)) {
            return [];
        }

        $out = [];
        foreach ($urls as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);
            $out[] = [
                'url' => $url,
                'local' => $this->toLocalPath($url),
            ];
        }

        return $out;
    }

    public function toLocalPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (is_file($url)) {
            return $url;
        }

        if (str_starts_with($url, 'file://')) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && is_file($path)) {
                return $path;
            }

            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        // http://localhost:5001/storage/uploads/xxx.jpg → storage/app/public/uploads/xxx.jpg
        if (preg_match('#/storage/(.+)$#', $path, $matches) === 1) {
            $local = storage_path('app/public/'.$matches[1]);
            if (is_file($local)) {
                return $local;
            }
        }

        // Seed demo images under public/seed/
        if (preg_match('#/seed/(.+)$#', $path, $matches) === 1) {
            $local = public_path('seed/'.$matches[1]);
            if (is_file($local)) {
                return $local;
            }
        }

        return null;
    }
}
