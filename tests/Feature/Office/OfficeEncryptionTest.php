<?php

use App\Casts\EncryptedText;
use App\Jobs\AnalyseBusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\BusinessMessage;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Helpers/Office.php';

/*
| Plan 41 VO-5: texts encrypted at rest, ids-only jobs, unreadable placeholder.
*/

it('stores message text and analysis texts encrypted', function () {
    $message = BusinessMessage::factory()->create(['text' => 'Secret client words']);
    $analysis = BusinessChatAnalysis::factory()->done('Secret summary')->create(['draft_reply' => 'Secret draft']);

    $rawMessage = DB::table('business_messages')->where('id', $message->id)->value('text');
    $rawAnalysis = (array) DB::table('business_chat_analyses')->where('id', $analysis->id)->first(['summary', 'next_step', 'draft_reply']);

    expect($rawMessage)->not->toContain('Secret')
        ->and(Crypt::decryptString($rawMessage))->toBe('Secret client words')
        ->and(implode(' ', $rawAnalysis))->not->toContain('Secret')
        ->and($message->fresh()->text)->toBe('Secret client words')
        ->and($analysis->fresh()->summary)->toBe('Secret summary');
});

it('reads a value encrypted with another key as the unreadable placeholder', function () {
    $message = BusinessMessage::factory()->create();
    $other = new Encrypter(random_bytes(32), 'AES-256-CBC');
    DB::table('business_messages')->where('id', $message->id)->update(['text' => $other->encryptString('Old words')]);

    expect($message->fresh()->text)->toBe(EncryptedText::UNREADABLE)
        ->and(EncryptedText::isReadable($message->fresh()->text))->toBeFalse();
});

it('queues the analysis job with the analysis id only, so failed_jobs can never hold text', function () {
    $analysis = BusinessChatAnalysis::factory()->create();
    $job = new AnalyseBusinessChat($analysis->id);

    $payload = serialize($job);

    expect($payload)->not->toContain('text')
        ->and(array_keys(get_object_vars($job)))->toContain('analysisId');

    $analysis->chat->messages()->create(['telegram_message_id' => 1, 'author' => 'client', 'kind' => 'text', 'text' => 'Plain client sentence']);
    expect(serialize(new AnalyseBusinessChat($analysis->id)))->not->toContain('Plain client sentence');
});

it('marks a pending analysis failed without any text when the job fails', function () {
    $analysis = BusinessChatAnalysis::factory()->create();

    (new AnalyseBusinessChat($analysis->id))->failed(new RuntimeException('Plain client sentence'));

    expect($analysis->fresh()->status->value)->toBe('failed')
        ->and($analysis->fresh()->error)->toBe('error');
});
