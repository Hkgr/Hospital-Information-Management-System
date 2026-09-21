<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\OncologyProcess;

class OncologyProcessTest extends TestCase
{
    public function test_readiness_already_buffered_before_waiting_is_not_lost(): void
    {
        $this->readyWorker('fwrite(STDOUT, "READY\n"); fflush(STDOUT); fgets(STDIN);', 'READY');
    }

    public function test_readiness_split_across_reads_is_not_lost(): void
    {
        $this->readyWorker('fwrite(STDOUT, "REA"); fflush(STDOUT); fgets(STDIN); fwrite(STDOUT, "DY\n"); fflush(STDOUT); fgets(STDIN);', 'REA', true);
    }

    private function readyWorker(string $script, string $initialOutput, bool $releasePartial = false): void
    {
        $worker = new Process([PHP_BINARY, '-r', $script]);
        $input = new InputStream;
        $worker->setInput($input);
        $worker->setTimeout(3);
        $worker->start();
        try {
            // Deliberately consume early output as Process::updateStatus can do.
            while (! str_contains($worker->getOutput(), $initialOutput)) {
                $worker->checkTimeout();
                if (! $worker->isRunning()) {
                    $this->fail('The test worker exited without the expected early output.');
                }
                usleep(1000);
            }
            if ($releasePartial) {
                $input->write("\n");
            }
            OncologyProcess::waitUntilReady($worker);
            $this->assertTrue($worker->isRunning(), 'Readiness must release the parent before the waiting worker finishes.');
        } finally {
            $input->close();
            $worker->stop();
        }
    }
}
