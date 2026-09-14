<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Catalog;
use App\Models\Package;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('installation-guide')]
#[Title('Laravilt installation guide')]
#[Description('Step-by-step instructions for installing Laravilt on a new Laravel 13 application with `php artisan laravilt:install --stack=vue|react`, including requirements, the official installation docs from laravilt/laravilt, which packages support the chosen frontend stack, and current package versions.')]
#[IsReadOnly]
#[IsIdempotent]
class InstallationGuide extends Tool
{
    public const STACKS = ['vue', 'react'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'stack' => $schema->string()
                ->enum(self::STACKS)
                ->description('Frontend stack of the Laravel app: "vue" (Inertia + Vue 3) or "react" (Inertia + React). Defaults to "vue".'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'stack' => ['nullable', 'string', 'in:'.implode(',', self::STACKS)],
        ], [
            'stack.in' => 'The stack must be "vue" or "react".',
        ]);

        $stack = $validated['stack'] ?? 'vue';
        $label = $stack === 'react' ? 'React' : 'Vue';
        $other = $stack === 'react' ? 'vue' : 'react';

        $meta = Catalog::metaPackage();
        $packages = Package::query()->ordered()->get();

        if ($packages->isEmpty()) {
            return Catalog::emptyIndexResponse();
        }

        $requirements = $meta ? Catalog::requirements($meta) : ['php' => null];
        $constraint = $meta?->latest_version ? ':^'.$meta->latest_version : '';

        $uiPackages = $packages->filter(fn (Package $package) => $package->has_vue || $package->has_react);
        $supporting = $uiPackages->filter(fn (Package $package) => $stack === 'react' ? $package->has_react : $package->has_vue);
        $missing = $uiPackages->diff($supporting);

        $parts = [
            "# Installing Laravilt ({$label} stack)",
            '_Latest `laravilt/laravilt`: '.($meta ? Catalog::version($meta) : 'unknown').' · '.trim(Catalog::lastSyncLine(), '_').'_',
            '## Quick start (Laravel 13)',
            implode("\n", [
                '```bash',
                "# 1. Create a Laravel 13 app with the {$label} starter kit",
                'laravel new my-app',
                'cd my-app',
                '',
                '# 2. Require Laravilt',
                'composer require laravilt/laravilt'.$constraint,
                '',
                "# 3. Install and choose the {$label} frontend",
                "php artisan laravilt:install --stack={$stack}",
                '',
                '# 4. Migrate, create an admin user and build assets',
                'php artisan migrate',
                'php artisan laravilt:user',
                'npm install && npm run build',
                '',
                'php artisan serve   # then open http://localhost:8000/admin',
                '```',
            ]),
            '## Choosing a stack',
            implode("\n", array_filter([
                '- `--stack=vue` publishes the Vue 3 + Inertia components (`resources/js` in each package).',
                '- `--stack=react` publishes the React + Inertia components (`resources/react` in each package).',
                "- Pick the stack that matches your Laravel starter kit; switching later means re-running `php artisan laravilt:install --stack={$other}`.",
                '- Packages with '.$label.' sources: '.($supporting->isEmpty() ? '_none detected yet_' : $supporting->map(fn (Package $p) => "`{$p->repo}`")->implode(', ')).'.',
                $missing->isNotEmpty()
                    ? '- Not yet shipping '.$label.' sources: '.$missing->map(fn (Package $p) => "`{$p->repo}`")->implode(', ').'. Check `get-package` before relying on their UI.'
                    : null,
            ])),
            '## Requirements',
            implode("\n", array_filter([
                '- Laravel 13 (Laravilt also supports Laravel 12)',
                $requirements['php'] ? '- PHP `'.$requirements['php'].'`' : '- PHP 8.3+',
                '- Composer 2, Node.js 20+ with npm',
                '- MySQL 8+, PostgreSQL 13+ or SQLite 3.35+',
            ])),
        ];

        $installation = Catalog::firstDocument([
            [Catalog::META_PACKAGE, 'docs/getting-started/installation.md'],
            [Catalog::META_PACKAGE, 'README.md'],
        ]);

        if ($installation !== null) {
            $parts[] = Catalog::embed($installation, 2, 6000, 'Official installation guide');
        }

        if ($interactive = Catalog::firstDocument([[Catalog::META_PACKAGE, 'docs/getting-started/interactive-install.md']])) {
            $parts[] = Catalog::embed($interactive, 2, 4000, 'Interactive installer prompts');
        }

        $parts[] = "## Package versions\n\n".$packages
            ->map(fn (Package $package) => '- `'.$package->name.'` '.Catalog::version($package))
            ->implode("\n");

        $next = collect([
            'docs/getting-started/quick-start.md',
            'docs/getting-started/first-resource.md',
            'docs/getting-started/configuration.md',
            'docs/getting-started/troubleshooting.md',
        ])->filter(fn (string $path) => $meta?->documents()->where('path', $path)->exists())
            ->map(fn (string $path) => '- '.Catalog::readDocHint($meta, $path));

        if ($next->isNotEmpty()) {
            $parts[] = "## Next steps\n\n".$next->implode("\n");
        }

        return Response::text(implode("\n\n", array_filter($parts)));
    }
}
