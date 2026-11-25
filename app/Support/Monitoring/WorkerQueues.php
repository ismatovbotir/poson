<?php

namespace App\Support\Monitoring;

/**
 * Queue list of the scheduled queue worker (routes/console.php, "queue-worker").
 * The AI-knowledge queue stays last so it never starves the others.
 */
final class WorkerQueues
{
    public static function list(): string
    {
        return implode(',', array_unique(array_filter([
            'default',
            (string) config('monitoring.queue', 'monitoring'),
            (string) config('chatbot.ai.product.queue', 'ai-admin'),
            (string) config('chatbot.ai.knowledge.queue', 'knowledge'),
        ])));
    }
}
