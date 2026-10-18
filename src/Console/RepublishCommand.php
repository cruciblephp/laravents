<?php

declare(strict_types=1);

namespace Laravents\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Laravents\Async\ExecuteHandlerJob;
use Laravents\Discovery\Registry;
use Laravents\Outbox\EventPublication;

final class RepublishCommand extends Command
{
    protected $signature = 'laravents:republish {--dry-run : List the publications that would be republished, without claiming them}';

    protected $description = 'Re-dispatch outbox publications that were never picked up: incomplete, and never dispatched or '
        .'dispatched longer ago than laravents.outbox.republish_after. A job that ran and failed is in failed_jobs; '
        .'bring it back with queue:retry, not with this command. Every connection in laravents.outbox.connections is searched.';

    public function handle(Dispatcher $bus, Registry $registry, Repository $config): int
    {
        $after = $config->get('laravents.outbox.republish_after', 300);
        $publications = [];

        foreach (EventPublication::outboxConnections() as $connection) {
            $publications = [...$publications, ...EventPublication::on($connection)
                ->incomplete()
                ->stale(is_int($after) ? $after : 300)
                ->orderBy('published_at')
                ->orderBy('id')
                ->get()
                ->all()];
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Publication', 'Connection', 'Listener', 'Published', 'Dispatched'],
                array_map(static fn (EventPublication $publication): array => [
                    $publication->id,
                    $publication->getConnectionName() ?? 'default',
                    $publication->listener,
                    $publication->published_at->toDateTimeString(),
                    $publication->dispatched_at?->toDateTimeString() ?? 'never',
                ], $publications),
            );
            $this->components->info(sprintf('%d %s would be republished.', count($publications), Str::plural('publication', count($publications))));

            return self::SUCCESS;
        }

        $republished = 0;

        foreach ($publications as $publication) {
            // Someone else re-dispatched or completed it since the select: theirs, not ours.
            if (! $publication->claim()) {
                continue;
            }

            $bus->dispatch((new ExecuteHandlerJob($publication->id, $publication->getConnectionName()))->withPublication($publication)->onQueue($registry->handler($publication->listener)?->queue));
            $republished++;
        }

        $this->components->info(sprintf('%d %s republished.', $republished, Str::plural('publication', $republished)));

        return self::SUCCESS;
    }
}
