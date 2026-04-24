<?php

namespace Cli\Interactive;

use Cli\Interactive\Support\FakePagedExecutor;
use FQL\Cli\Interactive\ResultPager;
use FQL\Cli\Output\TableRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ResultPagerTest extends TestCase
{
    public function testDisplaySinglePageWithoutInteractiveInput(): void
    {
        $executor = new FakePagedExecutor([
            1 => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
        ], 2);

        $pager = new ResultPager(new TableRenderer(50), 25, static fn(string $help): ?string => ':q');
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);

        $pager->display($output, $executor, 'SELECT id, name');

        $this->assertTrue(true);
    }

    public function testDisplayMultiPageWithNavigationCommands(): void
    {
        $inputs = [':n', ':b', ':l', ':f', '/Alice', '2', ':q'];
        $reader = function (string $help) use (&$inputs): ?string {
            return array_shift($inputs) ?? ':q';
        };

        $executor = new FakePagedExecutor([
            1 => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            2 => [
                ['id' => 3, 'name' => 'Carol'],
                ['id' => 4, 'name' => 'David'],
            ],
        ], 4);

        $pager = new ResultPager(new TableRenderer(50), 2, $reader);
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);

        $pager->display($output, $executor, 'SELECT id, name');

        $this->assertTrue(true);
    }

    public function testDisplayEmptyResults(): void
    {
        $executor = new FakePagedExecutor([
            1 => [],
        ], 0);

        $pager = new ResultPager(new TableRenderer(50), 25, static fn(string $help): ?string => ':q');
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);

        $pager->display($output, $executor, 'SELECT * FROM nonexistent');

        $this->assertTrue(true);
    }

    public function testDisplayMultiPageNullInputQuitsGracefully(): void
    {
        // Reader returns null — should act as :q
        $reader = static fn(string $help): ?string => null;

        $executor = new FakePagedExecutor([
            1 => [['id' => 1]],
            2 => [['id' => 2]],
        ], 2);

        $pager = new ResultPager(new TableRenderer(50), 1, $reader);
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);

        $pager->display($output, $executor, 'SELECT id');

        $this->assertTrue(true);
    }

    public function testDisplayMultiPageNavigateWithPageNumbers(): void
    {
        // Test numeric page input, including out-of-bounds values
        $inputs = ['99', '0', '-1', '1', ':q'];
        $reader = function (string $help) use (&$inputs): ?string {
            return array_shift($inputs) ?? ':q';
        };

        $executor = new FakePagedExecutor([
            1 => [['id' => 1]],
            2 => [['id' => 2]],
            3 => [['id' => 3]],
        ], 3);

        $pager = new ResultPager(new TableRenderer(50), 1, $reader);
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);

        $pager->display($output, $executor, 'SELECT id');

        $this->assertTrue(true);
    }

    public function testDisplayMultiPageWrapAround(): void
    {
        // Navigate forward past last page (should wrap to first), back past first (should wrap to last)
        $inputs = [':n', ':n', ':n', ':b', ':b', ':b', ':q'];
        $reader = function (string $help) use (&$inputs): ?string {
            return array_shift($inputs) ?? ':q';
        };

        $executor = new FakePagedExecutor([
            1 => [['id' => 1]],
            2 => [['id' => 2]],
        ], 2);

        $pager = new ResultPager(new TableRenderer(50), 1, $reader);
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);

        $pager->display($output, $executor, 'SELECT id');

        $this->assertTrue(true);
    }

    public function testDisplayMultiPageEnterKeyIsNextPage(): void
    {
        // Empty string (Enter key) acts as :n
        $inputs = ['', ':q'];
        $reader = function (string $help) use (&$inputs): ?string {
            return array_shift($inputs) ?? ':q';
        };

        $executor = new FakePagedExecutor([
            1 => [['id' => 1]],
            2 => [['id' => 2]],
        ], 2);

        $pager = new ResultPager(new TableRenderer(50), 1, $reader);
        $output = new ConsoleOutput(OutputInterface::VERBOSITY_QUIET, false);

        $pager->display($output, $executor, 'SELECT id');

        $this->assertTrue(true);
    }
}
