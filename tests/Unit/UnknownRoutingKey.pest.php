<?php

declare(strict_types=1);

use Laravents\Discovery\HandlerKind;
use Laravents\Exceptions\UnknownRoutingKeyException;

test('suggestions follow Symfony Console findAlternatives(), at most three, best first', function (string $key, string $message): void {
    $known = ['chat.archive', 'chat.close', 'chat.open', 'chat.opened', 'notification.send'];

    expect(UnknownRoutingKeyException::forKey(HandlerKind::Command, $key, $known)->getMessage())->toBe($message);
})->with([
    'one close key' => ['notification.sned', 'Unknown command routing key [notification.sned]. Did you mean [notification.send]?'],
    'closest first, then the module siblings, cut to three' => ['chat.opne', 'Unknown command routing key [chat.opne]. Did you mean one of these? [chat.open], [chat.opened], [chat.archive]'],
    'a key contained in registered ones' => ['open', 'Unknown command routing key [open]. Did you mean one of these? [chat.open], [chat.opened]'],
    'segment by segment, the closer action first' => ['chat.ope', 'Unknown command routing key [chat.ope]. Did you mean one of these? [chat.open], [chat.opened], [chat.archive]'],
    'a longer typo still ranks the nearer key first' => ['chat.openn', 'Unknown command routing key [chat.openn]. Did you mean one of these? [chat.open], [chat.opened], [chat.archive]'],
    'a clipped segment matches the action that contains it' => ['chat.pen', 'Unknown command routing key [chat.pen]. Did you mean one of these? [chat.open], [chat.opened], [chat.archive]'],
    'a far segment ranks the siblings by name' => ['chat.chat', 'Unknown command routing key [chat.chat]. Did you mean one of these? [chat.archive], [chat.close], [chat.open]'],
    'nothing close' => ['billing.refund', 'Unknown command routing key [billing.refund].'],
]);

test('with no keys registered there is nothing to suggest', function (): void {
    expect(UnknownRoutingKeyException::forKey(HandlerKind::Query, 'chat.get', [])->getMessage())
        ->toBe('Unknown query routing key [chat.get].');
});
