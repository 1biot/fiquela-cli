<?php

namespace FQL\Cli\Config;

class UpdateCheckResult
{
    public function __construct(
        public readonly string $latestVersion,
        public readonly bool $updateAvailable,
        public readonly ?string $pharDownloadUrl = null,
    ) {
    }
}
