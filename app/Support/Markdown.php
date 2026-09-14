<?php

namespace App\Support;

use Illuminate\Support\Str;

class Markdown
{
    /**
     * Split YAML-ish front matter from the body.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    public static function frontMatter(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);

        if (! preg_match('/\A---\n(.*?)\n---\n?/s', $content, $matches)) {
            return [[], $content];
        }

        $meta = [];

        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair)) {
                $meta[strtolower($pair[1])] = trim($pair[2], " \t\"'");
            }
        }

        return [$meta, substr($content, strlen($matches[0]))];
    }

    public static function title(string $content, string $path): string
    {
        [$meta, $body] = static::frontMatter($content);

        if (! empty($meta['title'])) {
            return $meta['title'];
        }

        if (str_ends_with(strtolower($path), '.md')) {
            foreach (static::lines(static::withoutCode($body)) as $line) {
                if (preg_match('/^#\s+(.+?)\s*#*\s*$/', $line, $matches)) {
                    return static::plain($matches[1]);
                }
            }
        }

        $file = pathinfo($path, PATHINFO_FILENAME);

        if (in_array(strtolower($file), ['readme', 'index'], true)) {
            $parent = basename(dirname($path));

            return $parent === '.' || $parent === '' || $parent === 'docs'
                ? Str::headline($file === 'index' ? 'Overview' : 'README')
                : Str::headline($parent);
        }

        return str_ends_with($path, '.json') ? basename($path) : Str::headline($file);
    }

    /**
     * Level 2 and 3 headings of a Markdown document.
     *
     * @return list<string>
     */
    public static function headings(string $content): array
    {
        [, $body] = static::frontMatter($content);

        $headings = [];

        foreach (static::lines(static::withoutCode($body)) as $line) {
            if (preg_match('/^(#{2,3})\s+(.+?)\s*#*\s*$/', $line, $matches)) {
                $headings[] = str_repeat('  ', strlen($matches[1]) - 2).static::plain($matches[2]);
            }
        }

        return array_values(array_unique($headings));
    }

    /**
     * A short plain-text summary taken from the first paragraphs.
     */
    public static function summary(string $content, int $limit = 600): string
    {
        [, $body] = static::frontMatter($content);

        $paragraphs = preg_split("/\n\s*\n/", static::withoutCode($body)) ?: [];
        $summary = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === ''
                || str_starts_with($paragraph, '#')
                || str_starts_with($paragraph, '<')
                || str_starts_with($paragraph, '[![')
                || str_starts_with($paragraph, '![')
                || str_starts_with($paragraph, '|')
                || str_starts_with($paragraph, '---')
                || preg_match('/^\s*([-*]|\d+\.)\s/', $paragraph)) {
                continue;
            }

            $text = static::plain($paragraph);

            if (mb_strlen($text) < 20) {
                continue;
            }

            $summary .= ($summary === '' ? '' : "\n\n").$text;

            if (mb_strlen($summary) >= $limit / 2) {
                break;
            }
        }

        return Str::limit($summary, $limit);
    }

    /**
     * Parse "## [x.y.z] - date" style changelog sections, skipping empty templates.
     *
     * @return list<array{title: string, body: string}>
     */
    public static function changelogEntries(string $content): array
    {
        [, $body] = static::frontMatter($content);

        $parts = preg_split('/^##\s+/m', str_replace("\r\n", "\n", $body)) ?: [];
        array_shift($parts);

        $entries = [];

        foreach ($parts as $part) {
            [$title, $text] = array_pad(explode("\n", $part, 2), 2, '');

            // Drop empty "### Added" style headings left over from the template.
            $cleaned = trim((string) preg_replace('/^###\s+\w+\s*$(?=\n*(?:###|\z))/m', '', trim($text)));

            if ($cleaned === '') {
                continue;
            }

            $entries[] = [
                'title' => trim($title),
                'body' => $cleaned,
            ];
        }

        return $entries;
    }

    /**
     * Split long text into pages on line boundaries.
     *
     * @return list<string>
     */
    public static function paginate(string $content, int $pageSize): array
    {
        $pageSize = max(1000, $pageSize);

        if (strlen($content) <= $pageSize) {
            return [$content];
        }

        $pages = [];
        $current = '';

        foreach (preg_split('/(?<=\n)/', $content) ?: [] as $line) {
            if ($current !== '' && strlen($current) + strlen($line) > $pageSize) {
                $pages[] = $current;
                $current = '';
            }

            while (strlen($line) > $pageSize) {
                $pages[] = mb_strcut($line, 0, $pageSize);
                $line = mb_strcut($line, $pageSize);
            }

            $current .= $line;
        }

        if ($current !== '') {
            $pages[] = $current;
        }

        return $pages;
    }

    /**
     * Extract the section under the first heading matching the pattern.
     */
    public static function section(string $content, string $pattern): ?string
    {
        [, $body] = static::frontMatter($content);
        $lines = static::lines($body);
        $capturing = false;
        $level = 0;
        $inCode = false;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inCode = ! $inCode;
            }

            if (! $inCode && preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                $headingLevel = strlen($matches[1]);

                if ($capturing && $headingLevel <= $level) {
                    break;
                }

                if (! $capturing && preg_match($pattern, static::plain($matches[2]))) {
                    $capturing = true;
                    $level = $headingLevel;
                }
            }

            if ($capturing) {
                $buffer[] = $line;
            }
        }

        return $buffer === [] ? null : trim(implode("\n", $buffer));
    }

    /**
     * Remove the front matter and the first H1 so documents can be embedded.
     */
    public static function body(string $content): string
    {
        [, $body] = static::frontMatter($content);

        return trim((string) preg_replace('/\A\s*#\s+[^\n]*\n/', '', $body));
    }

    /**
     * Shift headings so an embedded document nests under a parent heading.
     */
    public static function demoteHeadings(string $content, int $by = 1): string
    {
        $inCode = false;

        return implode("\n", array_map(function (string $line) use (&$inCode, $by): string {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inCode = ! $inCode;
            }

            if (! $inCode && preg_match('/^(#{1,6})(\s+.*)$/', $line, $matches)) {
                return str_repeat('#', min(6, strlen($matches[1]) + $by)).$matches[2];
            }

            return $line;
        }, static::lines($content)));
    }

    public static function plain(string $text): string
    {
        $text = (string) preg_replace('/<[^>]+>/', '', $text);
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/[*_`]{1,3}([^*_`]+)[*_`]{1,3}/', '$1', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        // Strip leading emoji and symbols often used in README headings.
        return trim((string) preg_replace('/^[^\p{L}\p{N}\[(`@]+/u', '', trim($text)));
    }

    /**
     * @return list<string>
     */
    protected static function lines(string $content): array
    {
        return explode("\n", str_replace("\r\n", "\n", $content));
    }

    protected static function withoutCode(string $content): string
    {
        return (string) preg_replace('/^\s*(```|~~~).*?^\s*\1\s*$/ms', '', str_replace("\r\n", "\n", $content));
    }
}
