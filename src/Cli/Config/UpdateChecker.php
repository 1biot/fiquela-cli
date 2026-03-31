<?php

namespace FQL\Cli\Config;

class UpdateChecker
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/1biot/fiquela-cli/releases/latest';
    private const PHAR_ASSET_NAME = 'fiquela-cli.phar';
    private const CACHE_FILE = 'update-check.json';
    private const CHECK_INTERVAL = 86400;

    private string $cacheFile;
    private string $currentVersion;
    private int $checkInterval;

    public function __construct(
        string $configDir,
        string $currentVersion,
        int $checkInterval = self::CHECK_INTERVAL,
    ) {
        $this->cacheFile = $configDir . '/' . self::CACHE_FILE;
        $this->currentVersion = $currentVersion;
        $this->checkInterval = $checkInterval;
    }

    public function check(): ?UpdateCheckResult
    {
        try {
            $cached = $this->readCache();
            if ($cached !== null && (time() - $cached['checked_at']) < $this->checkInterval) {
                return new UpdateCheckResult(
                    $cached['latest_version'],
                    version_compare($this->currentVersion, $cached['latest_version'], '<'),
                    $cached['phar_download_url'] ?? null,
                );
            }

            $release = $this->fetchLatestRelease();
            if ($release === null) {
                return null;
            }

            $latestVersion = ltrim($release['tag_name'], 'v');
            $pharUrl = $this->findPharAssetUrl($release);
            $this->writeCache($latestVersion, $pharUrl);

            return new UpdateCheckResult(
                $latestVersion,
                version_compare($this->currentVersion, $latestVersion, '<'),
                $pharUrl,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{latest_version: string, checked_at: int, phar_download_url?: string|null}|null
     */
    private function readCache(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (
            !is_array($data)
            || !isset($data['latest_version'])
            || !isset($data['checked_at'])
            || !is_string($data['latest_version'])
            || !is_int($data['checked_at'])
        ) {
            return null;
        }

        $result = [
            'latest_version' => $data['latest_version'],
            'checked_at' => $data['checked_at'],
        ];

        if (isset($data['phar_download_url']) && is_string($data['phar_download_url'])) {
            $result['phar_download_url'] = $data['phar_download_url'];
        }

        return $result;
    }

    private function writeCache(string $latestVersion, ?string $pharDownloadUrl): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                return;
            }
        }

        $cacheData = [
            'latest_version' => $latestVersion,
            'checked_at' => time(),
        ];

        if ($pharDownloadUrl !== null) {
            $cacheData['phar_download_url'] = $pharDownloadUrl;
        }

        $json = json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        file_put_contents($this->cacheFile, $json . "\n");
    }

    /**
     * @param array<string, mixed> $release
     */
    private function findPharAssetUrl(array $release): ?string
    {
        if (!isset($release['assets']) || !is_array($release['assets'])) {
            return null;
        }

        foreach ($release['assets'] as $asset) {
            if (
                is_array($asset)
                && isset($asset['name'], $asset['browser_download_url'])
                && $asset['name'] === self::PHAR_ASSET_NAME
            ) {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    /**
     * @codeCoverageIgnore
     * @return array<string, mixed>|null
     */
    protected function fetchLatestRelease(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'header' => "User-Agent: fiquela-cli\r\n",
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['tag_name']) || !is_string($data['tag_name'])) {
            return null;
        }

        return $data;
    }
}
