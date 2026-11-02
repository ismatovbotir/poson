<?php

namespace App\Console\Commands\Fakes;

use Illuminate\Console\Command;

class FakeSecretCommand extends Command
{
    protected $signature = 'fake:secret {name? : Who} {--api-token=sk_live_FAKE_TOKEN_123 : API token} {--apikey=sk_apikey_FAKE} {--authtoken=tok_FAKE_9} {--mode=fast : Run mode}';

    protected $description = 'Fake <info>command</info> for tests';

    protected $help = "Fake help <comment>text</comment> and <fg=red;options=bold>red</>\n<script>alert(1)</script>";

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

class FakeHiddenCommand extends Command
{
    protected $signature = 'fake:hidden';

    protected $description = 'Hidden fake command';

    protected $hidden = true;

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
