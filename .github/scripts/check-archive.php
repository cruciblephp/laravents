<?php

declare(strict_types=1);

/*
 * What a tag ships: the Composer archive for a ref must carry the package and none of what .gitattributes marks
 * export-ignore. NEVER_SHIP is the floor a deleted .gitattributes line cannot lower.
 *
 * Run: php .github/scripts/check-archive.php [ref]   (default HEAD)
 */

/** Paths an installed vendor/laravents/laravents cannot work without. */
const REQUIRED = [
    'composer.json',
    'LICENSE',
    'README.md',
    'CHANGELOG.md',
    'CONTRIBUTING.md',
    'SECURITY.md',
    'config/laravents.php',
    'routes/http.php',
    'src/LaraventsServiceProvider.php',
    'stubs/provider.stub',
];

/** Never in the package, whatever .gitattributes says. */
const NEVER_SHIP = [
    '.github',
    'assets',
    'docs',
    'scripts',
    'index.html',
    'docs.html',
    'changelog.html',
    'sitemap.xml',
    'tests',
    'workbench',
    'crucible.php',
    'testbench.yaml',
];

/** Runs a command and returns its stdout, or exits naming the command that failed. */
function run(string $command): string
{
    $output = [];
    exec($command, $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, "failed: {$command}\n");
        exit(2);
    }

    return implode("\n", $output);
}

$ref = $argv[1] ?? 'HEAD';

if (preg_match('/^[A-Za-z0-9._\/-]+$/', $ref) !== 1) {
    fwrite(STDERR, "not a ref: {$ref}\n");
    exit(2);
}

$attributes = file_get_contents('.gitattributes');

if ($attributes === false) {
    fwrite(STDERR, "cannot read .gitattributes\n");
    exit(2);
}

$ignored = [];

foreach (explode("\n", $attributes) as $line) {
    if (preg_match('#^/(\S+)\s+export-ignore\b#', trim($line), $match) === 1 && ! str_contains($match[1], '*')) {
        $ignored[] = $match[1];
    }
}

$entries = array_values(array_filter(
    explode("\n", run('git archive --format=tar '.escapeshellarg($ref).' | tar -t')),
    static fn (string $entry): bool => $entry !== '',
));
$shipped = array_fill_keys(array_map(static fn (string $entry): string => rtrim($entry, '/'), $entries), true);

$problems = [];

foreach (array_unique([...NEVER_SHIP, ...$ignored]) as $path) {
    foreach (array_keys($shipped) as $entry) {
        if ($entry === $path || str_starts_with($entry, $path.'/')) {
            $problems[] = in_array($path, NEVER_SHIP, true)
                ? "ships {$entry}, which must never ship"
                : "ships {$entry}, which .gitattributes marks export-ignore";

            break;
        }
    }
}

foreach (REQUIRED as $path) {
    if (! isset($shipped[$path])) {
        $problems[] = "does not ship {$path}";
    }
}

if ($problems !== []) {
    fwrite(STDERR, "The archive for {$ref}:\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, "  {$problem}\n");
    }

    exit(1);
}

printf(
    "The archive for %s: %d entries, the %d required paths present, none of the %d export-ignored ones.\n",
    $ref,
    count($entries),
    count(REQUIRED),
    count($ignored),
);
