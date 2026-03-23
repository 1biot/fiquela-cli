<?php

namespace FQL\Cli\Config;

class UpdateChecker
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/1biot/fiquela-cli/releases/latest';
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
                $latestVersion = $cached['latest_version'];
            } else {
                $latestVersion = $this->fetchLatestVersion();
                if ($latestVersion === null) {
                    return null;
                }
                $this->writeCache($latestVersion);
            }

            return new UpdateCheckResult(
                $latestVersion,
                version_compare($this->currentVersion, $latestVersion, '<'),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{latest_version: string, checked_at: int}|null
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

        return [
            'latest_version' => $data['latest_version'],
            'checked_at' => $data['checked_at'],
        ];
    }

    private function writeCache(string $latestVersion): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                return;
            }
        }

        $json = json_encode([
            'latest_version' => $latestVersion,
            'checked_at' => time(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        file_put_contents($this->cacheFile, $json . "\n");
    }

    /**
     * @codeCoverageIgnore
     */
    protected function fetchLatestVersion(): ?string
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

        return ltrim($data['tag_name'], 'v');
    }
}
