<?php

namespace Cli\Config;

use FQL\Cli\Config\UpdateChecker;
use FQL\Cli\Config\UpdateCheckResult;
use PHPUnit\Framework\TestCase;

class FakeUpdateChecker extends UpdateChecker
{
    private ?string $fakeLatestVersion;

    public function __construct(
        string $configDir,
        string $currentVersion,
        ?string $fakeLatestVersion,
        int $checkInterval = 86400,
    ) {
        parent::__construct($configDir, $currentVersion, $checkInterval);
        $this->fakeLatestVersion = $fakeLatestVersion;
    }

    protected function fetchLatestVersion(): ?string
    {
        return $this->fakeLatestVersion;
    }
}

class UpdateCheckerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/fql-update-test-' . uniqid();
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

    public function testCheckReturnsUpdateAvailable(): void
    {
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
    }

    public function testCheckReturnsNoUpdateWhenCurrent(): void
    {
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '2.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertFalse($result->updateAvailable);
    }

    public function testCheckReturnsNoUpdateWhenNewer(): void
    {
        $checker = new FakeUpdateChecker($this->tempDir, '3.0.0', '2.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertFalse($result->updateAvailable);
    }

    public function testCheckReturnsNullOnNetworkFailure(): void
    {
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', null);
        $result = $checker->check();

        $this->assertNull($result);
    }

    public function testCheckWritesCacheFile(): void
    {
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.0.0');
        $checker->check();

        $cacheFile = $this->tempDir . '/update-check.json';
        $this->assertFileExists($cacheFile);

        $data = json_decode((string) file_get_contents($cacheFile), true);
        $this->assertIsArray($data);
        $this->assertEquals('3.0.0', $data['latest_version']);
        $this->assertArrayHasKey('checked_at', $data);
    }

    public function testCheckUsesCacheWithinInterval(): void
    {
        // Write a fresh cache
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => '2.5.0',
            'checked_at' => time(),
        ]) . "\n");

        // Use a checker that would return null from fetch — but cache should be used
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', null);
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('2.5.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
    }

    public function testCheckRefreshesCacheAfterInterval(): void
    {
        // Write an expired cache
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => '2.5.0',
            'checked_at' => time() - 90000,
        ]) . "\n");

        // Fetch returns new version
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.1.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.1.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
    }

    public function testCheckHandlesCorruptedCacheFile(): void
    {
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, 'not valid json');

        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
    }

    public function testCheckHandlesIncompleteCacheData(): void
    {
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode(['latest_version' => '2.5.0']) . "\n");

        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
    }

    public function testCheckWithCustomInterval(): void
    {
        // Write cache that is 10 seconds old
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => '2.5.0',
            'checked_at' => time() - 10,
        ]) . "\n");

        // With 5 second interval, cache should be expired
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.0.0', 5);
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
    }

    public function testCheckCreatesDirectoryIfNeeded(): void
    {
        $nestedDir = $this->tempDir . '/nested/deep';
        $checker = new FakeUpdateChecker($nestedDir, '2.0.0', '3.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertFileExists($nestedDir . '/update-check.json');

        // Cleanup
        unlink($nestedDir . '/update-check.json');
        rmdir($nestedDir);
        rmdir($this->tempDir . '/nested');
    }

    public function testCheckCacheWithNonStringVersion(): void
    {
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => 123,
            'checked_at' => time(),
        ]) . "\n");

        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
    }

    public function testCheckCacheWithNonIntCheckedAt(): void
    {
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => '2.5.0',
            'checked_at' => 'not-a-number',
        ]) . "\n");

        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', '3.0.0');
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
    }

    public function testCheckNetworkFailureWithExpiredCacheReturnsNull(): void
    {
        // Expired cache + network failure = null
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => '2.5.0',
            'checked_at' => time() - 90000,
        ]) . "\n");

        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', null);
        $result = $checker->check();

        $this->assertNull($result);
    }

    public function testCheckNetworkFailureWithNoCacheReturnsNull(): void
    {
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', null);
        $result = $checker->check();

        $this->assertNull($result);
    }
}
