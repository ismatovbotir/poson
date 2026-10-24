<?php

namespace Tests\Support;

use App\Services\Ai\AiException;
use App\Services\Ai\TextAssistant;
use App\Services\Ai\TextResult;
use App\Services\Ai\TextTask;

/**
 * TextAssistant for tests: returns fixed texts (or throws), records tasks;
 * $during runs inside the call (e.g. to simulate a stale check meanwhile).
 */
class FakeTextAssistant implements TextAssistant
{
    /** @var list<TextTask> */
    public array $tasks = [];

    /**
     * @param  array<string, string>  $texts
     */
    public function __construct(
        public array $texts = ['uz' => 'Yorliq printeri Zebra ZD421', 'ru' => 'Принтер этикеток Zebra ZD421', 'en' => 'Label printer Zebra ZD421'],
        public ?AiException $throw = null,
        public int $inputTokens = 1000,
        public int $outputTokens = 500,
        public ?\Closure $during = null,
    ) {}

    /** @var list<string> */
    public array $models = [];

    public function generate(TextTask $task, string $model): TextResult
    {
        return $this->answer($task, $model);
    }

    public function translate(TextTask $task, string $model): TextResult
    {
        $result = $this->answer($task, $model);
        $texts = $result->texts;
        $texts[(string) $task->sourceLocale] = $task->sourceText;

        return new TextResult($texts, $result->model, $result->inputTokens, $result->outputTokens, $result->costMicroUsd);
    }

    private function answer(TextTask $task, string $model): TextResult
    {
        $this->tasks[] = $task;
        $this->models[] = $model;

        if ($this->during !== null) {
            ($this->during)($task);
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new TextResult($this->texts, $model, $this->inputTokens, $this->outputTokens);
    }
}
