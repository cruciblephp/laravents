<?php

declare(strict_types=1);

namespace Modules\Band\Handlers;

use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravents\Attributes\Authorize;
use Laravents\Attributes\CommandHandler;
use Laravents\Attributes\Expose;
use Laravents\Attributes\QueryHandler;
use Laravents\Attributes\Transactional;
use Modules\Band\Api\GetNickname;
use Modules\Band\Api\GiveNickname;
use Modules\Band\Api\NicknameView;
use Modules\Band\Api\ReplaceMember;
use Modules\Band\Models\Lineup;
use Modules\Band\Models\Nickname;
use Workbench\App\Models\User;

final class BandHandlers
{
    #[CommandHandler]
    #[Authorize]
    #[Transactional]
    #[Expose]
    public function give(GiveNickname $command, #[CurrentUser] Authenticatable $actor): int
    {
        // Creating the nickname publishes NicknameGiven (#[Publishes] on the model), inside this command's transaction.
        return Nickname::query()->create([
            'given_by' => $actor->getAuthIdentifier(),
            'member_id' => $command->memberId,
            'nickname' => $command->nickname,
        ])->id;
    }

    #[CommandHandler]
    #[Authorize]
    #[Transactional]
    public function replace(ReplaceMember $command): int
    {
        $out = User::query()->findOrFail($command->outId);
        $in = User::query()->findOrFail($command->inId);
        $instrument = $out->instrument;

        $out->forceFill(['is_member' => false, 'instrument' => null])->save();
        $in->forceFill(['is_member' => true, 'instrument' => $instrument])->save();

        // Recording the change publishes MemberReplaced (#[Publishes] on the model), inside this command's transaction.
        return Lineup::query()->create(['out_id' => $out->id, 'in_id' => $in->id, 'instrument' => $instrument])->id;
    }

    #[QueryHandler]
    #[Expose]
    public function get(GetNickname $query): NicknameView
    {
        $nickname = Nickname::query()->findOrFail($query->id);

        return new NicknameView($nickname->id, $nickname->given_by, $nickname->member_id, $nickname->nickname, $nickname->created_at->toImmutable());
    }
}
