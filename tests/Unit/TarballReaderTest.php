<?php

use App\Support\TarballReader;
use Tests\Support\Tarball;

function tarballFile(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'tar-test-');
    file_put_contents($path, Tarball::make($files));

    return $path;
}

it('reads selected files and strips the root directory', function () {
    $path = tarballFile([
        'README.md' => '# Hello',
        'docs/' => '',
        'docs/intro.md' => str_repeat('x', 1500),
        'src/Foo.php' => '<?php',
        'resources/react/app.tsx' => 'export {}',
    ]);

    $result = (new TarballReader)->read($path, fn (string $file) => str_ends_with($file, '.md'));

    expect($result['files'])->toBe([
        'README.md' => '# Hello',
        'docs/intro.md' => str_repeat('x', 1500),
    ])->and($result['paths'])->toContain('src/Foo.php', 'resources/react/app.tsx', 'docs/')
        ->and($result['paths'])->not->toContain('pax_global_header');

    unlink($path);
});

it('supports PAX long path names', function () {
    $long = 'docs/'.str_repeat('very-long-directory/', 6).'guide.md';
    $path = tarballFile([$long => '# Long']);

    $result = (new TarballReader)->read($path, fn () => true);

    expect($result['files'])->toBe([$long => '# Long']);

    unlink($path);
});

it('ignores path traversal entries', function () {
    $path = tarballFile(['../evil.md' => 'nope', 'ok.md' => 'yes']);

    $result = (new TarballReader)->read($path, fn () => true);

    expect($result['files'])->toBe(['ok.md' => 'yes']);

    unlink($path);
});
