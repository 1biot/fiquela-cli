<?php

namespace Cli\Command;

use Cli\Config\FakeUpdateChecker;
use FQL\Cli\Command\SelfUpdateCommand;
use FQL\Cli\Config\UpdateCheckResult;
use FQL\Cli\Config\UpdateChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class NonPharSelfUpdateCommand extends SelfUpdateCommand
{
    protected function getPharPath(): string
    {
        return '';
    }
}

class FakePharSelfUpdateCommand extends SelfUpdateCommand
{
    private string $fakePharPath;

    public function __construct(string $fakePharPath, ?UpdateChecker $updateChecker = null)
    {
        parent::__construct($updateChecker);
        $this->fakePharPath = $fakePharPath;
    }

    protected function getPharPath(): string
    {
        return $this->fakePharPath;
    }
}

class SelfUpdateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fql-selfupdate-test-' . uniqid();
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function createTester(SelfUpdateCommand $command): CommandTester
    {
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('self-update'));
    }

    public function testNotRunningFromPhar(): void
    {
        $command = new NonPharSelfUpdateCommand();
        $tester = $this->createTester($command);
        $tester->execute([]);

        $this->assertEquals(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('only available when running from PHAR', $tester->getDisplay());
        $this->assertStringContainsString('install.sh', $tester->getDisplay());
    }

    public function testCheckReturnsNull(): void
    {
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', null);
        $command = new FakePharSelfUpdateCommand($this->tempDir . '/fake.phar', $checker);
        $tester = $this->createTester($command);
        $tester->execute([]);

        $this->assertEquals(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Failed to fetch latest release', $tester->getDisplay());
    }

    public function testAlreadyUpToDate(): void
    {
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('2.0.0'),
        );
        $command = new FakePharSelfUpdateCommand($this->tempDir . '/fake.phar', $checker);
        $tester = $this->createTester($command);
        $tester->execute([]);

        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Already up to date', $tester->getDisplay());
    }

    public function testNoPharAssetInRelease(): void
    {
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '1.0.0',
            FakeUpdateChecker::releaseFromVersion('2.0.0', false),
        );
        $command = new FakePharSelfUpdateCommand($this->tempDir . '/fake.phar', $checker);
        $tester = $this->createTester($command);
        $tester->execute([]);

        $this->assertEquals(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('PHAR asset not found', $tester->getDisplay());
    }

    public function testPharPathNotWritable(): void
    {
        $nonWritablePath = '/nonexistent/path/fake.phar';

        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '1.0.0',
            FakeUpdateChecker::releaseFromVersion('2.0.0', true),
        );
        $command = new FakePharSelfUpdateCommand($nonWritablePath, $checker);
        $tester = $this->createTester($command);
        $tester->execute([]);

        $this->assertEquals(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Cannot write to', $tester->getDisplay());
        $this->assertStringContainsString('sudo', $tester->getDisplay());
    }
}
