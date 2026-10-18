<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
 * The claims in README.md and the manual under docs/ that a test can hold: every `php artisan laravents:` line in a
 * fenced bash block names a command the console kernel has, with options that command defines; every relative link
 * resolves to a file; the manual's index (docs/README.md) lists every page, and every page it lists exists.
 *
 * The manual is every Markdown file under docs/ except the generated example output (docs/laravents/).
 */

/** The repository root. */
function repositoryPath(string $path = ''): string
{
    return dirname(__DIR__, 3).($path === '' ? '' : '/'.$path);
}

/**
 * The manual's pages, relative to docs/, sorted.
 *
 * @return list<string>
 */
function manualPages(): array
{
    $pages = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(repositoryPath('docs'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'md') {
            continue;
        }

        $page = substr($file->getPathname(), strlen(repositoryPath('docs')) + 1);

        if (str_starts_with($page, 'laravents/')) {
            continue;
        }

        $pages[] = $page;
    }

    sort($pages);

    return $pages;
}

/**
 * Every `php artisan laravents:...` line inside a ```bash fence of the given Markdown.
 *
 * @return list<string>
 */
function manualCommands(string $markdown): array
{
    preg_match_all('/^```bash\n(.*?)^```$/ms', $markdown, $fences);
    $commands = [];

    foreach ($fences[1] as $fence) {
        foreach (explode("\n", $fence) as $line) {
            if (str_starts_with(trim($line), 'php artisan laravents:')) {
                $commands[] = trim($line);
            }
        }
    }

    return $commands;
}

/**
 * What is wrong with one command line against the registered commands: an unknown command, an unknown option.
 *
 * @return list<string>
 */
function manualCommandProblems(string $line): array
{
    $words = preg_split('/\s+/', trim(substr($line, strlen('php artisan '))));
    $name = is_array($words) ? (string) array_shift($words) : '';
    $command = Artisan::all()[$name] ?? null;

    if ($command === null) {
        return [$line.': no command '.$name];
    }

    $definition = $command->getDefinition();
    $problems = [];

    foreach ($words ?: [] as $word) {
        if (str_starts_with($word, '--')) {
            $option = explode('=', substr($word, 2), 2)[0];
            $known = $definition->hasOption($option);
        } elseif (str_starts_with($word, '-') && strlen($word) > 1) {
            $option = substr($word, 1, 1);
            $known = $definition->hasShortcut($option);
        } else {
            continue;
        }

        if (! $known) {
            $problems[] = $line.': '.$name.' has no option '.$word;
        }
    }

    return $problems;
}

/**
 * The relative link targets of the given Markdown, without their #fragment: links in fenced blocks and code spans,
 * absolute URLs (any scheme) and same-page anchors are left out.
 *
 * @return list<string>
 */
function manualLinks(string $markdown): array
{
    $prose = (string) preg_replace(['/^```.*?^```$/ms', '/`[^`\n]*`/'], '', $markdown);
    preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $prose, $matches);
    $links = [];

    foreach ($matches[1] as $target) {
        if (str_starts_with($target, '#') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
            continue;
        }

        $links[] = explode('#', $target, 2)[0];
    }

    return $links;
}

/**
 * The links of one file that do not resolve to a file, as "file: target".
 *
 * @return list<string>
 */
function brokenLinks(string $file): array
{
    $broken = [];

    foreach (manualLinks((string) file_get_contents($file)) as $target) {
        if (! is_file(dirname($file).'/'.$target)) {
            $broken[] = substr($file, strlen(repositoryPath()) + 1).': '.$target;
        }
    }

    return $broken;
}

test('every laravents command in a README bash block exists with the options it uses', function (): void {
    $commands = manualCommands((string) file_get_contents(repositoryPath('README.md')));

    expect($commands)->toContain('php artisan laravents:module Band', 'php artisan laravents:docs --check')
        ->and(array_merge(...array_map(manualCommandProblems(...), $commands)))->toBe([]);
});

test('a README line with an unknown command or option is reported', function (): void {
    $markdown = "```bash\nphp artisan laravents:nope\nphp artisan laravents:docs --chek -x\nphp artisan migrate\n```\n";

    expect(manualCommands($markdown))->toBe(['php artisan laravents:nope', 'php artisan laravents:docs --chek -x'])
        ->and(array_merge(...array_map(manualCommandProblems(...), manualCommands($markdown))))->toBe([
            'php artisan laravents:nope: no command laravents:nope',
            'php artisan laravents:docs --chek -x: laravents:docs has no option --chek',
            'php artisan laravents:docs --chek -x: laravents:docs has no option -x',
        ]);
});

test('the manual is the twelve pages and its index, and the generated files are not in it', function (): void {
    expect(manualPages())->toBe([
        'README.md',
        'commands-and-queries.md',
        'console.md',
        'events.md',
        'http-gateway.md',
        'installation.md',
        'interceptors.md',
        'metadata-and-actor.md',
        'modules.md',
        'outbox.md',
        'reference.md',
        'testing.md',
        'verification-and-docs.md',
    ]);
});

test('every laravents command in a bash block of the manual exists with the options it uses', function (): void {
    $commands = array_merge(...array_map(
        static fn (string $page): array => manualCommands((string) file_get_contents(repositoryPath('docs/'.$page))),
        manualPages(),
    ));

    expect($commands)->toContain('php artisan laravents:republish --dry-run', 'php artisan laravents:interceptor RateLimit --module=Band --before --pointcut=RateLimited')
        ->and(array_merge(...array_map(manualCommandProblems(...), $commands)))->toBe([]);
});

test('every relative link in the README and the manual resolves to a file', function (): void {
    $files = [repositoryPath('README.md'), ...array_map(static fn (string $page): string => repositoryPath('docs/'.$page), manualPages())];

    $links = array_merge(...array_map(static fn (string $file): array => manualLinks((string) file_get_contents($file)), $files));

    expect($links)->toContain('installation.md', 'laravents/README.md', '../workbench/modules/Band/Api/GiveNickname.php', 'docs/README.md')
        ->and(array_merge(...array_map(brokenLinks(...), $files)))->toBe([]);
});

test('the index lists every page of the manual, and every page it lists exists', function (): void {
    $listed = array_values(array_filter(
        manualLinks((string) file_get_contents(repositoryPath('docs/README.md'))),
        static fn (string $target): bool => str_ends_with($target, '.md') && ! str_starts_with($target, '../') && ! str_starts_with($target, 'laravents/'),
    ));

    expect($listed)->toHaveCount(12)
        ->and(array_filter($listed, static fn (string $page): bool => ! is_file(repositoryPath('docs/'.$page))))->toBe([])
        ->and(array_values(array_diff(manualPages(), ['README.md'], $listed)))->toBe([]);
});

test('links in code, absolute URLs and anchors are not checked; a fragment is dropped; a missing target is reported', function (): void {
    $markdown = "See [a](a.md#part), [web](https://laravel.com), [top](#top) and `[code](nope.md)`.\n\n```md\n[fenced](nope.md)\n```\n\n[gone](gone.md)\n";

    expect(manualLinks($markdown))->toBe(['a.md', 'gone.md']);
});
