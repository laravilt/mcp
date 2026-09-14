<?php

use App\Support\Markdown;

it('prefers the front matter title, then the first heading, then the file name', function () {
    expect(Markdown::title("---\ntitle: From Front Matter\n---\n# Heading", 'docs/a.md'))->toBe('From Front Matter')
        ->and(Markdown::title("```md\n# Not this\n```\n\n# 🚀 Real **Title**", 'docs/a.md'))->toBe('Real Title')
        ->and(Markdown::title('no heading', 'docs/getting-started/quick-start.md'))->toBe('Quick Start')
        ->and(Markdown::title('{}', 'composer.json'))->toBe('composer.json');
});

it('extracts level 2 and 3 headings outside code blocks', function () {
    $content = "# Title\n\n## Install\n\n```bash\n## not a heading\n```\n\n### Options\n\n#### Too deep";

    expect(Markdown::headings($content))->toBe(['Install', '  Options']);
});

it('summarizes the first prose paragraphs', function () {
    $content = "<p align=\"center\"><img src=\"x\"></p>\n\n# Package\n\n[![badge](x)](y)\n\nThe first real paragraph describes the [package](https://x) in detail.\n\n## Next";

    expect(Markdown::summary($content))->toBe('The first real paragraph describes the package in detail.');
});

it('parses changelog entries and drops empty template sections', function () {
    $content = "# Changelog\n\n## [Unreleased]\n\n### Added\n\n### Fixed\n\n## [1.1.0] - 2026-08-01\n\n### Added\n- Feature\n\n### Removed\n";

    expect(Markdown::changelogEntries($content))->toBe([
        ['title' => '[1.1.0] - 2026-08-01', 'body' => "### Added\n- Feature"],
    ]);
});

it('paginates long content on line boundaries', function () {
    $content = implode("\n", array_fill(0, 300, str_repeat('a', 20)))."\n";

    $pages = Markdown::paginate($content, 1000);

    expect($pages)->toHaveCount(7)
        ->and(implode('', $pages))->toBe($content)
        ->and(strlen($pages[0]))->toBeLessThanOrEqual(1000);
});

it('extracts a section by heading', function () {
    $content = "# Readme\n\n## Usage\n\nRun it.\n\n### Generate\n\nMore.\n\n## License\n\nMIT";

    expect(Markdown::section($content, '/^usage$/i'))->toBe("## Usage\n\nRun it.\n\n### Generate\n\nMore.");
});
