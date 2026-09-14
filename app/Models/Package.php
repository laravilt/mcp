<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Package extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'composer' => 'array',
            'package_json' => 'array',
            'has_vue' => 'boolean',
            'has_react' => 'boolean',
            'stars' => 'integer',
            'pushed_at' => 'datetime',
            'latest_release_at' => 'datetime',
            'content_synced_at' => 'datetime',
            'versions_synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * Resolve a package from "panel", "laravilt/panel" or a GitHub URL.
     */
    public static function findByIdentifier(?string $identifier): ?self
    {
        $identifier = Str::of((string) $identifier)
            ->trim()
            ->lower()
            ->replaceMatches('#^https?://github\.com/#', '')
            ->trim('/')
            ->toString();

        if ($identifier === '') {
            return null;
        }

        $repo = Str::contains($identifier, '/') ? Str::afterLast($identifier, '/') : $identifier;

        return static::query()
            ->where('name', $identifier)
            ->orWhere('repo', $repo)
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        // The meta package first, then the rest alphabetically.
        $query->orderByRaw("CASE WHEN name = 'laravilt/laravilt' THEN 0 ELSE 1 END")->orderBy('name');
    }

    public function installCommand(): string
    {
        return 'composer require '.$this->name.($this->latest_version ? ':^'.ltrim($this->latest_version, 'v') : '');
    }

    public function documentUrl(string $path): string
    {
        return rtrim($this->repo_url, '/').'/blob/'.$this->default_branch.'/'.ltrim($path, '/');
    }

    /**
     * @return array<string, string>
     */
    public function requirements(): array
    {
        return (array) ($this->composer['require'] ?? []);
    }

    public function frontendSummary(): string
    {
        return match (true) {
            $this->has_vue && $this->has_react => 'Vue + React',
            $this->has_vue => 'Vue',
            $this->has_react => 'React',
            default => 'PHP only',
        };
    }
}
