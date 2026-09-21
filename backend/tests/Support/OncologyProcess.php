<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class OncologyProcess
{
    public static function waitUntilReady(Process $worker): void
    {
        // Process may have read READY during start/updateStatus, before a
        // waitUntil callback exists. Inspect the complete per-worker buffer.
        while (! preg_match('/(?:^|\n)READY\r?\n/', $worker->getOutput())) {
            $worker->checkTimeout();
            if (! $worker->isRunning()) {
                throw new RuntimeException('Oncology worker exited before readiness.');
            }
            usleep(1000);
        }
    }
}
