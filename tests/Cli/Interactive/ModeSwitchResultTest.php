<?php

namespace Cli\Interactive;

use FQL\Cli\Interactive\HistoryManager;
use FQL\Cli\Interactive\ModeSwitchResult;
use FQL\Cli\Query\LocalQueryExecutor;
use PHPUnit\Framework\TestCase;

class ModeSwitchResultTest extends TestCase
{
    public function testOk(): void
    {
        $executor = new LocalQueryExecutor();
        $historyFile = sys_get_temp_dir() . '/fql-msr-test-' . uniqid();
        $history = new HistoryManager($historyFile);

        $result = ModeSwitchResult::ok($executor, $history);

        $this->assertTrue($result->success);
        $this->assertSame($executor, $result->executor);
        $this->assertSame($history, $result->historyManager);
        $this->assertNull($result->error);
    }

    public function testFail(): void
    {
        $result = ModeSwitchResult::fail('Connection refused');

        $this->assertFalse($result->success);
        $this->assertNull($result->executor);
        $this->assertNull($result->historyManager);
        $this->assertEquals('Connection refused', $result->error);
    }
}
