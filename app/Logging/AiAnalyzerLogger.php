<?php

namespace App\Logging;

use Illuminate\Support\Facades\Cache;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use App\Jobs\AnalyzeErrorLogJob;
use Illuminate\Support\Str;

class AiAnalyzerLogger extends AbstractProcessingHandler
{
    /**
     * Build the custom Monolog instance.
     */
    public function __invoke(array $config)
    {
        $logger = new \Monolog\Logger('ai_analyzer');
        $logger->pushHandler(new self(
            \Monolog\Logger::toMonologLevel($config['level'] ?? \Monolog\Logger::ERROR)
        ));
        
        return $logger;
    }

    /**
     * Writes the log record to the AI Job dispatcher safely.
     */
    protected function write(LogRecord|array $record): void
    {
        // Support Monolog V2 (Array) & V3 (Object)
        $message = $record['message'] ?? '';
        $context = $record['context'] ?? [];
        $level = is_object($record) ? $record->level->value : ($record['level'] ?? 400);

        // We only care about ERROR (400), CRITICAL (500), ALERT (550), EMERGENCY (600)
        if ($level < 400) {
            return;
        }

        // Avoid infinite loops if AI analysis itself causes an error
        if (Str::contains($message, 'AiService') || Str::contains($message, 'Http')) {
            return;
        }

        // Construct a lean, highly packed error snippet
        $snippet = "ERROR MESSAGE:\n" . $message . "\n";
        
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            $snippet .= "\nEXCEPTION:\n" . $e->getMessage() . "\n";
            $snippet .= "FILE: " . $e->getFile() . ":" . $e->getLine() . "\n";
            
            // Graab only top 5 lines of stack trace so we don't blow up token limits
            $trace = explode("\n", $e->getTraceAsString());
            $snippet .= "TRACE (TOP): " . implode("\n", array_slice($trace, 0, 5));
        }

        // Extremely safe size limit (~2000 chars)
        $snippet = substr($snippet, 0, 2000);

        // Anti-Spam (Throttle identical errors by caching an MD5 signature for 2 hours)
        $signature = md5($snippet);
        $cacheKey = 'ai_log_sent_' . $signature;

        if (!Cache::has($cacheKey)) {
            // Put in cache for 2 hours (120 minutes)
            Cache::put($cacheKey, true, now()->addHours(2));
            
            // Send to queue to be processed seamlessly by a background worker
            dispatch(new AnalyzeErrorLogJob($snippet));
        }
    }
}
