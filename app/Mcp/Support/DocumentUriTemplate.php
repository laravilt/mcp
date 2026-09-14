<?php

namespace App\Mcp\Support;

use Laravel\Mcp\Support\UriTemplate;

/**
 * laravilt://docs/{package}/{path} where {path} may contain slashes
 * (e.g. docs/getting-started/installation.md) or be URL encoded.
 */
class DocumentUriTemplate extends UriTemplate
{
    public const TEMPLATE = 'laravilt://docs/{package}/{path}';

    public function __construct()
    {
        parent::__construct(self::TEMPLATE);
    }

    /**
     * @return array<string, string>|null
     */
    public function match(string $uri): ?array
    {
        if (! preg_match('#^laravilt://docs/([^/]+)/(.+)$#', $uri, $matches)) {
            return null;
        }

        return [
            'package' => rawurldecode($matches[1]),
            'path' => rawurldecode($matches[2]),
        ];
    }

    public static function uri(string $package, string $path): string
    {
        return 'laravilt://docs/'.$package.'/'.$path;
    }
}
