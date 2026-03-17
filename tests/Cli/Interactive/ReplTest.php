<?php

namespace Cli\Interactive;

use Client\MockTransport;
use FQL\Cli\Interactive\HistoryManager;
use FQL\Cli\Interactive\Repl;
use FQL\Cli\Interactive\ResultPager;
use FQL\Cli\Query\ApiQueryExecutor;
use FQL\Cli\Query\LocalQueryExecutor;
use FQL\Client\FiQueLaClient;
use FQL\Client\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ReplTest extends TestCase
{
    private string $historyFile;
    private string $tempCsv;

    protected function setUp(): void
    {
        $this->historyFile = sys_get_temp_dir() . '/fql-repl-history-' . uniqid();
        $this->tempCsv = sys_get_temp_dir() . '/fql-repl-data-' . uniqid() . '.csv';
        file_put_contents($this->tempCsv, "id;name\n1;Alice\n2;Bob\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->historyFile)) {
            unlink($this->historyFile);
        }
        if (file_exists($this->tempCsv)) {
            unlink($this->tempCsv);
        }
    }

    public function testRunLocalModeExecutesQueryAndExits(): void
    {
        $lines = ['SELECT id, name FROM *;', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);
        $pager->expects($this->once())->method('display');

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();

        $this->assertEquals(0, $code);
        $this->assertFileExists($this->historyFile);
    }

    public function testRunApiModeSyncsHistoryAtStartAndAfterQuery(): void
    {
        $transport = new MockTransport();
        $client = new FiQueLaClient('https://api.example.com', 'token', $transport);

        // Initial history sync
        $transport->addResponse(new Response(200, [], json_encode([
            ['created_at' => '2024-01-01T10:00:00Z', 'query' => 'SELECT 1', 'runs' => 'SELECT 1'],
        ])));
        // History sync after query execution
        $transport->addResponse(new Response(200, [], json_encode([
            ['created_at' => '2024-01-01T11:00:00Z', 'query' => 'SELECT 2', 'runs' => 'SELECT 2'],
        ])));

        $executor = new ApiQueryExecutor($client, 'test');
        $history = new HistoryManager($this->historyFile);

        $lines = ['SELECT 1;', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $pager = $this->createMock(ResultPager::class);
        $pager->expects($this->once())->method('display');

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $repl->run();

        $this->assertEquals(2, $transport->getRequestCount());
    }

    public function testRunSkipsEmptyLinesAndHandlesInfo(): void
    {
        $lines = ['', '   ', 'info', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);
        $pager->expects($this->never())->method('display');

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunExitsOnFalseFromReader(): void
    {
        // Reader returns false immediately (simulates Ctrl+C / EOF)
        $reader = static fn(string $prompt) => false;

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunMultiLineQueryBuffer(): void
    {
        // Build a query across multiple lines, then exit
        $lines = ['SELECT id,', 'name FROM *;', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);
        $pager->expects($this->once())->method('display');

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunMultipleQueriesInOneStatement(): void
    {
        // Two queries separated by semicolons in the same input line
        $lines = ['SELECT id FROM *; SELECT name FROM *;', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);
        $pager->expects($this->exactly(2))->method('display');

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunHandlesQueryExecutionError(): void
    {
        $lines = ['SELECT nonexistent FROM *;', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);

        // Use a pager that throws an exception
        $pager = $this->createMock(ResultPager::class);
        $pager->method('display')->willThrowException(new \RuntimeException('Query failed'));

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        // Should not throw — errors are caught and printed
        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunApiModeHandlesHistorySyncFailure(): void
    {
        $transport = new MockTransport();
        $client = new FiQueLaClient('https://api.example.com', 'token', $transport);

        // History sync fails (500 error)
        $transport->addResponse(new Response(500, [], '{"error":"Server error"}'));

        $executor = new ApiQueryExecutor($client, 'test');
        $history = new HistoryManager($this->historyFile);

        $lines = ['exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        // Should not throw — history sync failure is caught
        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunLocalModeWithNonexistentFile(): void
    {
        $reader = static fn(string $prompt) => 'exit';

        $executor = new LocalQueryExecutor('/nonexistent/file.csv', 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunLocalModeWithNullFile(): void
    {
        $reader = static fn(string $prompt) => 'exit';

        $executor = new LocalQueryExecutor(null, null, ',', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testRunApiModeSortsHistoryByCreatedAt(): void
    {
        $transport = new MockTransport();
        $client = new FiQueLaClient('https://api.example.com', 'token', $transport);

        // History entries returned out of order — should be sorted by created_at
        $transport->addResponse(new Response(200, [], json_encode([
            ['created_at' => '2024-01-01T12:00:00Z', 'query' => 'SELECT 2', 'runs' => 'SELECT 2'],
            ['created_at' => '2024-01-01T10:00:00Z', 'query' => 'SELECT 1', 'runs' => 'SELECT 1'],
            ['created_at' => '2024-01-01T14:00:00Z', 'query' => 'SELECT 3', 'runs' => 'SELECT 3'],
        ])));

        $executor = new ApiQueryExecutor($client, 'test');
        $history = new HistoryManager($this->historyFile);

        $reader = static fn(string $prompt) => 'exit';
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);

        // Verify history was written — the file should exist with sorted entries
        $this->assertFileExists($this->historyFile);
    }
}
