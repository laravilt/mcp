<?php

namespace App\Services\Search;

use App\Models\Document;
use App\Support\Markdown;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class DocumentSearch
{
    protected ?bool $ftsAvailable = null;

    public function driver(): string
    {
        $driver = (string) config('laravilt.search.driver', 'auto');

        if ($driver === 'like') {
            return 'like';
        }

        return $this->ftsAvailable() ? 'fts' : 'like';
    }

    public function ftsAvailable(): bool
    {
        if ($this->ftsAvailable !== null) {
            return $this->ftsAvailable;
        }

        try {
            return $this->ftsAvailable = DB::connection()->getDriverName() === 'sqlite'
                && Schema::hasTable('documents_fts');
        } catch (Throwable) {
            return $this->ftsAvailable = false;
        }
    }

    public function index(Document $document): void
    {
        if (! $this->ftsAvailable()) {
            return;
        }

        DB::table('documents_fts')->where('rowid', $document->id)->delete();

        DB::table('documents_fts')->insert([
            'rowid' => $document->id,
            'title' => $document->title,
            'headings' => implode("\n", (array) $document->headings),
            'content' => Markdown::frontMatter($document->content)[1],
        ]);
    }

    /**
     * Rebuild the full-text index from the documents table.
     */
    public function rebuild(): int
    {
        if (! $this->ftsAvailable()) {
            return 0;
        }

        DB::table('documents_fts')->delete();

        $count = 0;

        Document::query()->orderBy('id')->chunkById(100, function (Collection $documents) use (&$count): void {
            $documents->each(function (Document $document) use (&$count): void {
                $this->index($document);
                $count++;
            });
        });

        return $count;
    }

    /**
     * @param  iterable<int>  $ids
     */
    public function remove(iterable $ids): void
    {
        $ids = collect($ids)->values();

        if ($ids->isEmpty() || ! $this->ftsAvailable()) {
            return;
        }

        $ids->chunk(500)->each(fn (Collection $chunk) => DB::table('documents_fts')->whereIn('rowid', $chunk->all())->delete());
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', Str::lower($query), $matches);

        return collect($matches[0])
            ->filter(fn (string $token): bool => mb_strlen($token) > 1 || is_numeric($token))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @return list<array{document: Document, score: float, snippet: string}>
     */
    public function search(string $query, ?int $packageId = null, int $limit = 10): array
    {
        $tokens = static::tokens($query);

        if ($tokens === []) {
            return [];
        }

        return $this->driver() === 'fts'
            ? $this->searchFts($tokens, $packageId, $limit)
            : $this->searchLike($tokens, $packageId, $limit);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{document: Document, score: float, snippet: string}>
     */
    protected function searchFts(array $tokens, ?int $packageId, int $limit): array
    {
        $terms = array_map(fn (string $token): string => '"'.str_replace('"', '""', $token).'"*', $tokens);

        // Try all terms first, then relax to any term.
        $rows = $this->ftsQuery(implode(' ', $terms), $packageId, $limit);

        if ($rows === [] && count($terms) > 1) {
            $rows = $this->ftsQuery(implode(' OR ', $terms), $packageId, $limit);
        }

        $documents = Document::query()
            ->with('package')
            ->whereIn('id', array_column($rows, 'id'))
            ->get()
            ->keyBy('id');

        $results = [];

        foreach ($rows as $row) {
            $document = $documents->get($row->id);

            if ($document === null) {
                continue;
            }

            $results[] = [
                'document' => $document,
                'score' => round(-1 * (float) $row->score, 4),
                'snippet' => $this->cleanSnippet((string) $row->snippet),
            ];
        }

        return $results;
    }

    /**
     * @return list<object{id: int, score: float, snippet: string}>
     */
    protected function ftsQuery(string $match, ?int $packageId, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT d.id AS id,
                   bm25(documents_fts, 10.0, 4.0, 1.0)
                     * (CASE WHEN d.path LIKE '%.json' THEN 0.3 WHEN d.path = 'CHANGELOG.md' THEN 0.6 ELSE 1.0 END) AS score,
                   snippet(documents_fts, 2, '**', '**', ' … ', 32) AS snippet
            FROM documents_fts
            JOIN documents d ON d.id = documents_fts.rowid
            WHERE documents_fts MATCH ?
        SQL;

        $bindings = [$match];

        if ($packageId !== null) {
            $sql .= ' AND d.package_id = ?';
            $bindings[] = $packageId;
        }

        $sql .= ' ORDER BY score ASC LIMIT ?';
        $bindings[] = $limit;

        try {
            return DB::select($sql, $bindings);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Portable ranking for databases without FTS5.
     *
     * @param  list<string>  $tokens
     * @return list<array{document: Document, score: float, snippet: string}>
     */
    protected function searchLike(array $tokens, ?int $packageId, int $limit): array
    {
        $candidates = Document::query()
            ->with('package')
            ->when($packageId, fn ($query) => $query->where('package_id', $packageId))
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.addcslashes($token, '%_\\').'%';
                    $query->orWhere('title', 'like', $like)->orWhere('content', 'like', $like);
                }
            })
            ->limit(500)
            ->get();

        return $candidates
            ->map(function (Document $document) use ($tokens): array {
                $title = Str::lower($document->title);
                $headings = Str::lower(implode("\n", (array) $document->headings));
                $content = Str::lower($document->content);
                $score = 0.0;
                $matched = 0;

                foreach ($tokens as $token) {
                    $inTitle = substr_count($title, $token);
                    $inHeadings = substr_count($headings, $token);
                    $inContent = substr_count($content, $token);

                    if ($inTitle + $inHeadings + $inContent > 0) {
                        $matched++;
                    }

                    $score += $inTitle * 10 + min($inHeadings, 5) * 4 + log(1 + $inContent);
                }

                $score *= $matched / count($tokens);
                $score *= str_ends_with($document->path, '.json') ? 0.3 : ($document->path === 'CHANGELOG.md' ? 0.6 : 1.0);

                return [
                    'document' => $document,
                    'score' => round($score, 4),
                    'snippet' => $this->likeSnippet($document->content, $tokens),
                ];
            })
            ->filter(fn (array $result): bool => $result['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function likeSnippet(string $content, array $tokens, int $radius = 160): string
    {
        $lower = Str::lower($content);
        $position = null;

        foreach ($tokens as $token) {
            $found = mb_strpos($lower, $token);

            if ($found !== false && ($position === null || $found < $position)) {
                $position = $found;
            }
        }

        $position ??= 0;
        $start = max(0, $position - $radius);
        $snippet = mb_substr($content, $start, $radius * 2);

        foreach ($tokens as $token) {
            $snippet = (string) preg_replace('/('.preg_quote($token, '/').')/iu', '**$1**', $snippet);
        }

        return $this->cleanSnippet(($start > 0 ? ' … ' : '').$snippet.' … ');
    }

    protected function cleanSnippet(string $snippet): string
    {
        $snippet = (string) preg_replace('/\s+/', ' ', $snippet);
        $snippet = str_replace(['```', '****', '** **'], ['', '', ' '], $snippet);

        return trim($snippet);
    }
}
