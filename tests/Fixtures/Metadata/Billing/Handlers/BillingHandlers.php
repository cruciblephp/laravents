<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Metadata\Billing\Handlers;

use Illuminate\Container\Attributes\Context as FromContext;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Header;
use Laravents\Attributes\QueryHandler;
use Laravents\Contracts\EventBus;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Audit;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Charge;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Charged;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Coerce;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Explode;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Inspect;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\SendReceipt;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\Tag;
use Laravents\Tests\Fixtures\Metadata\Billing\Api\WhoAmI;
use Laravents\Tests\Fixtures\Metadata\Ids;
use Laravents\Tests\Fixtures\Recorder;
use RuntimeException;

final class BillingHandlers
{
    #[CommandHandler]
    public function charge(
        Charge $command,
        EventBus $events,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
        #[Header('causation_id')] ?string $causationId,
    ): string {
        Recorder::record('charge', Ids::capture($messageId, $correlationId, $causationId));
        $events->publish(new Charged($command->amount));
        Recorder::record('charge after publish', Context::get('message_id'));

        return $messageId;
    }

    #[CommandHandler]
    public function sendReceipt(
        SendReceipt $command,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
        #[Header('causation_id')] ?string $causationId,
    ): void {
        Recorder::record('sendReceipt', Ids::capture($messageId, $correlationId, $causationId));
        Log::info('Receipt sent.', ['amount' => $command->amount]);
    }

    /** @return array<string, scalar|null> */
    #[QueryHandler]
    public function inspect(
        Inspect $query,
        #[Header('message_id')] string $messageId,
        #[Header('correlation_id')] string $correlationId,
        #[Header('causation_id')] ?string $causationId,
        #[Header('actor_id')] ?string $actorId,
        #[Header('actor_guard')] ?string $actorGuard,
        #[Header('timestamp')] string $timestamp,
    ): array {
        return [
            'message_id' => $messageId,
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
            'actor_id' => $actorId,
            'actor_guard' => $actorGuard,
            'timestamp' => $timestamp,
        ];
    }

    /** @return array{mixed, string, string} */
    #[QueryHandler]
    public function whoAmI(
        WhoAmI $query,
        #[CurrentUser] ?Authenticatable $user,
        #[FromContext('correlation_id')] string $fromContext,
        #[Header('correlation_id')] string $fromHeader,
    ): array {
        return [$user?->getAuthIdentifier(), $fromContext, $fromHeader];
    }

    #[CommandHandler]
    public function audit(Audit $command, #[Header('tenant_id')] string $tenantId): string
    {
        return $tenantId;
    }

    /** @return array{int, bool, float, string, ?string} */
    #[QueryHandler]
    public function coerce(
        Coerce $query,
        #[Header('n')] int $n,
        #[Header('flag')] bool $flag,
        #[Header('ratio')] float $ratio,
        #[Header('label')] string $label = 'none',
        #[Header('nothing')] ?string $nothing = null,
    ): array {
        return [$n, $flag, $ratio, $label, $nothing];
    }

    #[CommandHandler]
    public function tag(Tag $command, EventBus $events): void
    {
        Context::add('tenant', $command->tenant);
        $events->publish(new Charged(1));
    }

    #[CommandHandler]
    public function explode(Explode $command): never
    {
        throw new RuntimeException('Exploded.');
    }
}
