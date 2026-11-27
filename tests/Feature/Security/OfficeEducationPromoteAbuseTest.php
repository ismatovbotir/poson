<?php

use App\Actions\Office\PromoteFeedbackToExample;
use App\Enums\AgentRole;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\BusinessChat;
use App\Models\OfficeDraftFeedback;
use App\Models\TelegramGroupMember;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Helpers/Office.php';
require_once __DIR__.'/../../Helpers/Groups.php';
require_once __DIR__.'/../Office/Education/ReviewPromoteTest.php';

/*
| Plan 46 §9, abuse cases 7 (full) and 8: Promote guard variants, wrong password, non-admin, missing tick (ED-1, ED46-5).
*/

it('abuse 7: obfuscated numbers, links, phones and the participant name are blocked in every text', function (string $text) {
    $feedback = promotable();
    $versions = AgentProfile::forRole(AgentRole::Sales)->versions()->count();

    foreach (['client', 'reply', 'note'] as $field) {
        expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData([$field => 'Please '.$text.' thanks'])))->toThrow(ValidationException::class);
    }

    expect(AgentProfile::forRole(AgentRole::Sales)->versions()->count())->toBe($versions)
        ->and($feedback->fresh()->promoted_version_id)->toBeNull();
})->with([
    'spaced digits' => '9 9 8 9 0 1 2 3 4 5 6',
    'bracket dot' => 'poson[.]uz',
    'zero-width phone' => "+998\u{200B}90\u{200B}123\u{200B}45\u{200B}67",
    'first name' => 'Zarnigor',
    'first name lower case' => 'zarnigor',
    'username' => 'fake_zarnigor',
    'username with at' => '@fake_zarnigor',
    'email' => 'a@b.co',
    'link' => 'https://example.com',
    'card' => '8600 1234 5678 9012',
]);

it('abuse 7: the names of the current members of a group are blocked too', function () {
    officeAgents();
    $g = feedbackGroup();
    $member = TelegramGroupMember::factory()->unknown()->create(['telegram_group_id' => $g['group']->id, 'first_name' => 'Shoxruxbek', 'username' => 'fake_shoxrux']);
    $feedback = OfficeDraftFeedback::factory()->edited('Final')->create(['business_chat_id' => null, 'business_chat_analysis_id' => null, 'telegram_group_id' => $g['group']->id, 'telegram_group_thread_id' => $g['thread']->id, 'telegram_group_analysis_id' => $g['analysis']->id, 'role' => AgentRole::Sales]);

    foreach ([$member->first_name, strtoupper($member->first_name), $member->username] as $name) {
        expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData(['reply' => "Salom $name, qaysi oʻlcham?"])))->toThrow(ValidationException::class);
    }
});

it('abuse 7: BrandSafeText still applies after the guards', function () {
    $feedback = promotable();

    expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData(['reply' => 'Biz 10% chegirma beramiz'])))->toThrow(ValidationException::class);
});

it('abuse 8: a wrong password, a missing tick or a non-admin is refused and nothing is written', function (string $case) {
    $feedback = promotable();
    $versions = AgentProfile::forRole(AgentRole::Sales)->versions()->count();
    $actor = staffUser($case === 'sales' ? Role::SalesManager : Role::Admin);
    $data = match ($case) {
        'wrong password' => promoteData(['password' => 'not-the-password']),
        'no password' => promoteData(['password' => '']),
        'no tick' => promoteData(['anonymised' => false]),
        'no tick missing' => (function () {
            $data = promoteData();
            unset($data['anonymised']);

            return $data;
        })(),
        default => promoteData(),
    };

    expect(fn () => app(PromoteFeedbackToExample::class)->handle($actor, $feedback, $data))->toThrow($case === 'sales' ? AuthorizationException::class : ValidationException::class)
        ->and(AgentProfile::forRole(AgentRole::Sales)->versions()->count())->toBe($versions)
        ->and($feedback->fresh()->promoted_version_id)->toBeNull();
})->with(['wrong password', 'no password', 'no tick', 'no tick missing', 'sales']);

it('abuse 8: an Admin session without the password is not enough, whatever the recent confirmation says', function () {
    confirmAdminPassword();
    $feedback = promotable();

    expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData(['password' => 'wrong'])))->toThrow(ValidationException::class);
});

it('abuse 8: the 6th password attempt within a minute is throttled, even with the right password', function () {
    $feedback = promotable();
    $admin = staffUser();
    $attempt = fn (string $password) => app(PromoteFeedbackToExample::class)->handle($admin, $feedback, promoteData(['password' => $password]));

    foreach (range(1, 5) as $_) {
        expect(fn () => $attempt('wrong-password'))->toThrow(ValidationException::class, __('office.review.errors.password'));
    }

    expect(fn () => $attempt('password'))->toThrow(ValidationException::class, __('office.review.errors.password_throttled'))
        ->and($feedback->fresh()->promoted_version_id)->toBeNull();

    RateLimiter::clear('office-promote-password:'.$admin->id);
    expect($attempt('password')->id)->not->toBeNull();
});

it('Promote never publishes, and a feedback of a deleted chat cannot be promoted', function () {
    $feedback = promotable();
    $profile = AgentProfile::forRole(AgentRole::Sales);
    $published = $profile->current_version_id;

    app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback, promoteData());

    expect($profile->fresh()->current_version_id)->toBe($published);

    $other = promotableAgain();
    BusinessChat::query()->whereKey($other->business_chat_id)->delete();

    expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $other, promoteData()))->toThrow(ValidationException::class);
});

function promotableAgain(): OfficeDraftFeedback
{
    $f = feedbackChat();

    return OfficeDraftFeedback::factory()->edited('Final')->create(['business_chat_id' => $f['chat']->id, 'business_chat_analysis_id' => $f['analysis']->id, 'role' => AgentRole::Sales]);
}

it('abuse 7: the name check folds apostrophes and scripts (O\'tkir, Oʻtkir, Otkir, Зарнигор, Zarnigor)', function (string $first, string $text) {
    $feedback = promotable();
    BusinessChat::query()->whereKey($feedback->business_chat_id)->update(['first_name' => $first, 'username' => null]);

    expect(fn () => app(PromoteFeedbackToExample::class)->handle(staffUser(), $feedback->fresh(), promoteData(['reply' => "Salom $text, qaysi oʻlcham?"])))->toThrow(ValidationException::class);
})->with([
    'ascii apostrophe in the text' => ['Oʻtkir', "O'tkir"],
    'okina in the text' => ['O\'tkir', 'Oʻtkir'],
    'no apostrophe in the text' => ['Oʻtkir', 'Otkir'],
    'modifier apostrophe' => ['Gʻulom', 'Gʼulom'],
    'curly apostrophe' => ['Gʻulom', 'G’ulom'],
    'latin name, Cyrillic text' => ['Zarnigor', 'Зарнигор'],
    'Cyrillic name, Latin text' => ['Зарнигор', 'Zarnigor'],
    'Cyrillic name, Latin text with sh' => ['Шохрух', 'Shokhrukh'],
    'Cyrillic name in upper case' => ['Зарнигор', 'ЗАРНИГОР'],
    'x for kh, Latin name' => ['Хуршид', 'Xurshid'],
    'x for kh, Cyrillic text' => ['Xurshid', 'Хуршид'],
    'j for zh' => ['Жасур', 'Jasur'],
    'j for zh, Cyrillic text' => ['Jasur', 'Жасур'],
    'zh for zh' => ['Жасур', 'Zhasur'],
]);
