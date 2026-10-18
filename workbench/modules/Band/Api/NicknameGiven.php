<?php

declare(strict_types=1);

namespace Modules\Band\Api;

use Illuminate\Database\Eloquent\Model;
use Laravents\Concerns\Publishable;
use LogicException;

/**
 * Event: a member was given a nickname. Published when a Nickname is created (#[Publishes] on the model).
 */
final readonly class NicknameGiven
{
    use Publishable;

    public function __construct(
        public int $nicknameId,
        public int $givenBy,
        public int $memberId,
        public string $nickname,
    ) {}

    /** Takes Eloquent's Model, not Nickname, so the Api references nothing of the module outside it. */
    public static function fromModel(Model $model): static
    {
        $nickname = $model->getAttribute('nickname');

        return new self(
            self::id($model->getKey()),
            self::id($model->getAttribute('given_by')),
            self::id($model->getAttribute('member_id')),
            is_string($nickname) ? $nickname : throw new LogicException('A nickname is a string.'),
        );
    }

    private static function id(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new LogicException('A nickname names its members by integer id.');
    }
}
