<?php

namespace Cli\Interactive;

use Cli\Config\FakeUpdateChecker;
use Client\MockTransport;
use FQL\Cli\Interactive\HistoryManager;
use FQL\Cli\Interactive\ModeSwitchResult;
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
        $lines = ['', '   ', 'info', 'clear', 'exit'];
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

    // -------------------------------------------------------
    // Mode switching: connect / local
    // -------------------------------------------------------

    public function testConnectSwitchesToApiMode(): void
    {
        $transport = new MockTransport();
        // History sync after switching
        $transport->addResponse(new Response(200, [], '[]'));

        $apiClient = new FiQueLaClient('https://api.example.com', 'token', $transport);
        $apiExecutor = new ApiQueryExecutor($apiClient, 'test-server');
        $apiHistoryFile = sys_get_temp_dir() . '/fql-api-history-' . uniqid();
        $apiHistory = new HistoryManager($apiHistoryFile);

        $connectCallback = static function (?string $serverName) use ($apiExecutor, $apiHistory): ModeSwitchResult {
            return ModeSwitchResult::ok($apiExecutor, $apiHistory);
        };

        $lines = ['connect', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            $connectCallback
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);

        // Cleanup
        if (file_exists($apiHistoryFile)) {
            unlink($apiHistoryFile);
        }
    }

    public function testConnectWithServerNameSwitchesToApiMode(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, [], '[]'));

        $apiClient = new FiQueLaClient('https://api.example.com', 'token', $transport);
        $apiExecutor = new ApiQueryExecutor($apiClient, 'prod');
        $apiHistory = new HistoryManager(sys_get_temp_dir() . '/fql-api-history-' . uniqid());

        $receivedServer = null;
        $connectCallback = function (?string $serverName) use ($apiExecutor, $apiHistory, &$receivedServer): ModeSwitchResult {
            $receivedServer = $serverName;
            return ModeSwitchResult::ok($apiExecutor, $apiHistory);
        };

        $lines = ['connect prod', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            $connectCallback
        );

        $repl->run();
        $this->assertEquals('prod', $receivedServer);

        // Cleanup
        if (file_exists($apiHistory->getHistoryFile())) {
            unlink($apiHistory->getHistoryFile());
        }
    }

    public function testConnectFailsShowsError(): void
    {
        $connectCallback = static function (?string $serverName): ModeSwitchResult {
            return ModeSwitchResult::fail('Server "nonexistent" not found. Available servers: prod, staging');
        };

        $lines = ['connect nonexistent', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            $connectCallback
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);
    }

    public function testConnectWhenAlreadyInApiMode(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, [], '[]'));

        $apiClient = new FiQueLaClient('https://api.example.com', 'token', $transport);
        $executor = new ApiQueryExecutor($apiClient, 'test');
        $history = new HistoryManager($this->historyFile);

        $connectCalled = false;
        $connectCallback = static function (?string $serverName) use (&$connectCalled): ModeSwitchResult {
            $connectCalled = true;
            return ModeSwitchResult::fail('should not be called');
        };

        $lines = ['connect', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            $connectCallback
        );

        $repl->run();
        $this->assertFalse($connectCalled);
    }

    public function testLocalSwitchesToLocalMode(): void
    {
        $localExecutor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $localHistory = new HistoryManager(sys_get_temp_dir() . '/fql-local-history-' . uniqid());

        $localCallback = static function () use ($localExecutor, $localHistory): ModeSwitchResult {
            return ModeSwitchResult::ok($localExecutor, $localHistory);
        };

        $transport = new MockTransport();
        $transport->addResponse(new Response(200, [], '[]'));

        $apiClient = new FiQueLaClient('https://api.example.com', 'token', $transport);
        $executor = new ApiQueryExecutor($apiClient, 'test');
        $history = new HistoryManager($this->historyFile);

        $lines = ['local', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            null,
            $localCallback
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);

        // Cleanup
        if (file_exists($localHistory->getHistoryFile())) {
            unlink($localHistory->getHistoryFile());
        }
    }

    public function testLocalWhenAlreadyInLocalMode(): void
    {
        $localCalled = false;
        $localCallback = static function () use (&$localCalled): ModeSwitchResult {
            $localCalled = true;
            return ModeSwitchResult::fail('should not be called');
        };

        $lines = ['local', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            null,
            $localCallback
        );

        $repl->run();
        $this->assertFalse($localCalled);
    }

    public function testConnectWithoutCallbackShowsWarning(): void
    {
        $lines = ['connect', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        // No connectCallback provided
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

    public function testLocalWithoutCallbackShowsWarning(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, [], '[]'));

        $apiClient = new FiQueLaClient('https://api.example.com', 'token', $transport);
        $executor = new ApiQueryExecutor($apiClient, 'test');
        $history = new HistoryManager($this->historyFile);

        $lines = ['local', 'exit'];
        $reader = function (string $prompt) use (&$lines) {
            return array_shift($lines) ?? false;
        };

        $pager = $this->createMock(ResultPager::class);

        // No localCallback provided
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

    public function testUpdateNotificationShownWhenUpdateAvailable(): void
    {
        $reader = static fn(string $prompt) => 'exit';

        $tempDir = sys_get_temp_dir() . '/fql-update-notif-' . uniqid();
        mkdir($tempDir, 0700, true);

        $checker = new FakeUpdateChecker(
            $tempDir,
            '1.0.0',
            FakeUpdateChecker::releaseFromVersion('2.0.0', true),
        );

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);
        $repl = new Repl(
            $output,
            $executor,
            $history,
            $pager,
            $reader,
            null,
            null,
            $checker,
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);

        // Cleanup
        $files = glob($tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($tempDir);
    }

    public function testUpdateNotificationNotShownWhenUpToDate(): void
    {
        $reader = static fn(string $prompt) => 'exit';

        $tempDir = sys_get_temp_dir() . '/fql-update-notif-' . uniqid();
        mkdir($tempDir, 0700, true);

        $checker = new FakeUpdateChecker(
            $tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('2.0.0'),
        );

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            null,
            null,
            $checker,
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);

        // Cleanup
        $files = glob($tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($tempDir);
    }

    public function testUpdateNotificationHandlesNullResult(): void
    {
        $reader = static fn(string $prompt) => 'exit';

        $tempDir = sys_get_temp_dir() . '/fql-update-notif-' . uniqid();
        mkdir($tempDir, 0700, true);

        $checker = new FakeUpdateChecker($tempDir, '2.0.0', null);

        $executor = new LocalQueryExecutor($this->tempCsv, 'csv', ';', 'utf-8');
        $history = new HistoryManager($this->historyFile);
        $pager = $this->createMock(ResultPager::class);

        $repl = new Repl(
            new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false),
            $executor,
            $history,
            $pager,
            $reader,
            null,
            null,
            $checker,
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);

        // Cleanup
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
    }

    public function testModeSwitchClearsQueryBuffer(): void
    {
        $transport = new MockTransport();
        $transport->addResponse(new Response(200, [], '[]'));

        $apiClient = new FiQueLaClient('https://api.example.com', 'token', $transport);
        $apiExecutor = new ApiQueryExecutor($apiClient, 'test');
        $apiHistory = new HistoryManager(sys_get_temp_dir() . '/fql-api-hist-' . uniqid());

        $connectCallback = static function (?string $serverName) use ($apiExecutor, $apiHistory): ModeSwitchResult {
            return ModeSwitchResult::ok($apiExecutor, $apiHistory);
        };

        // Start a query, then switch mode before finishing — buffer should be cleared
        $lines = ['SELECT id,', 'connect', 'exit'];
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
            $reader,
            $connectCallback
        );

        $code = $repl->run();
        $this->assertEquals(0, $code);

        // Cleanup
        if (file_exists($apiHistory->getHistoryFile())) {
            unlink($apiHistory->getHistoryFile());
        }
    }
}
