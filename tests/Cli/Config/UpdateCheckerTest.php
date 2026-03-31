<?php

namespace Cli\Config;

use FQL\Cli\Config\UpdateChecker;
use FQL\Cli\Config\UpdateCheckResult;
use PHPUnit\Framework\TestCase;

class FakeUpdateChecker extends UpdateChecker
{
    /** @var array<string, mixed>|null */
    private ?array $fakeRelease;

    /**
     * @param array<string, mixed>|null $fakeRelease
     */
    public function __construct(
        string $configDir,
        string $currentVersion,
        ?array $fakeRelease,
        int $checkInterval = 86400,
    ) {
        parent::__construct($configDir, $currentVersion, $checkInterval);
        $this->fakeRelease = $fakeRelease;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchLatestRelease(): ?array
    {
        return $this->fakeRelease;
    }

    /**
     * @param string|null $version Shortcut to create a minimal release array
     */
    public static function releaseFromVersion(?string $version, bool $withPhar = false): ?array
    {
        if ($version === null) {
            return null;
        }

        $release = ['tag_name' => 'v' . $version, 'assets' => []];

        if ($withPhar) {
            $release['assets'][] = [
                'name' => 'fiquela-cli.phar',
                'browser_download_url' => 'https://github.com/1biot/fiquela-cli/releases/download/v'
                    . $version . '/fiquela-cli.phar',
            ];
        }

        return $release;
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
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0'),
        );
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
    }

    public function testCheckReturnsNoUpdateWhenCurrent(): void
    {
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('2.0.0'),
        );
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertFalse($result->updateAvailable);
    }

    public function testCheckReturnsNoUpdateWhenNewer(): void
    {
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '3.0.0',
            FakeUpdateChecker::releaseFromVersion('2.0.0'),
        );
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
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0', true),
        );
        $checker->check();

        $cacheFile = $this->tempDir . '/update-check.json';
        $this->assertFileExists($cacheFile);

        $data = json_decode((string) file_get_contents($cacheFile), true);
        $this->assertIsArray($data);
        $this->assertEquals('3.0.0', $data['latest_version']);
        $this->assertArrayHasKey('checked_at', $data);
        $this->assertArrayHasKey('phar_download_url', $data);
        $this->assertStringContainsString('fiquela-cli.phar', $data['phar_download_url']);
    }

    public function testCheckUsesCacheWithinInterval(): void
    {
        // Write a fresh cache
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => '2.5.0',
            'checked_at' => time(),
            'phar_download_url' => 'https://example.com/fiquela-cli.phar',
        ]) . "\n");

        // Use a checker that would return null from fetch — but cache should be used
        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', null);
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('2.5.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
        $this->assertEquals('https://example.com/fiquela-cli.phar', $result->pharDownloadUrl);
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
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.1.0'),
        );
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.1.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
    }

    public function testCheckHandlesCorruptedCacheFile(): void
    {
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, 'not valid json');

        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0'),
        );
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
        $this->assertTrue($result->updateAvailable);
    }

    public function testCheckHandlesIncompleteCacheData(): void
    {
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode(['latest_version' => '2.5.0']) . "\n");

        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0'),
        );
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
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0'),
            5,
        );
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('3.0.0', $result->latestVersion);
    }

    public function testCheckCreatesDirectoryIfNeeded(): void
    {
        $nestedDir = $this->tempDir . '/nested/deep';
        $checker = new FakeUpdateChecker(
            $nestedDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0'),
        );
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

        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0'),
        );
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

        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0'),
        );
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

    public function testCheckReturnsPharDownloadUrl(): void
    {
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0', true),
        );
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertNotNull($result->pharDownloadUrl);
        $this->assertStringContainsString('fiquela-cli.phar', $result->pharDownloadUrl);
    }

    public function testCheckReturnsNullPharUrlWhenNoAsset(): void
    {
        $checker = new FakeUpdateChecker(
            $this->tempDir,
            '2.0.0',
            FakeUpdateChecker::releaseFromVersion('3.0.0', false),
        );
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertNull($result->pharDownloadUrl);
    }

    public function testCacheWithoutPharUrlStillWorks(): void
    {
        // Legacy cache without phar_download_url
        $cacheFile = $this->tempDir . '/update-check.json';
        file_put_contents($cacheFile, json_encode([
            'latest_version' => '2.5.0',
            'checked_at' => time(),
        ]) . "\n");

        $checker = new FakeUpdateChecker($this->tempDir, '2.0.0', null);
        $result = $checker->check();

        $this->assertInstanceOf(UpdateCheckResult::class, $result);
        $this->assertEquals('2.5.0', $result->latestVersion);
        $this->assertNull($result->pharDownloadUrl);
    }
}
