<?php

namespace FQL\Cli\Command;

use FQL\Cli\Application;
use FQL\Cli\Config\ConfigManager;
use FQL\Cli\Config\UpdateCheckResult;
use FQL\Cli\Config\UpdateChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateCommand extends Command
{
    private ?UpdateChecker $updateChecker;

    public function __construct(?UpdateChecker $updateChecker = null)
    {
        parent::__construct();
        $this->updateChecker = $updateChecker;
    }

    protected function configure(): void
    {
        $this
            ->setName('self-update')
            ->setDescription('Update FiQueLa CLI to the latest version (PHAR only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pharPath = $this->getPharPath();
        if ($pharPath === '') {
            $output->writeln('<error>Self-update is only available when running from PHAR.</error>');
            $output->writeln(
                'Use the install script instead: '
                . 'curl -fsSL https://raw.githubusercontent.com/1biot/fiquela-cli/main/install.sh | bash'
            );
            return Command::FAILURE;
        }

        $output->writeln('Checking for updates...');

        $checker = $this->updateChecker ?? $this->createUpdateChecker();
        $result = $checker->check();

        if ($result === null) {
            $output->writeln('<error>Failed to fetch latest release from GitHub.</error>');
            return Command::FAILURE;
        }

        if (!$result->updateAvailable) {
            $output->writeln(sprintf(
                '<info>Already up to date (version %s).</info>',
                Application::VERSION,
            ));
            return Command::SUCCESS;
        }

        if ($result->pharDownloadUrl === null) {
            $output->writeln(sprintf(
                '<error>PHAR asset not found in release %s.</error>',
                $result->latestVersion,
            ));
            return Command::FAILURE;
        }

        if (!is_writable($pharPath)) {
            $output->writeln(sprintf(
                '<error>Cannot write to %s. Try running with sudo.</error>',
                $pharPath,
            ));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Updating from %s to %s...', Application::VERSION, $result->latestVersion));

        $tempPath = $pharPath . '.tmp';
        if (!$this->downloadFile($result->pharDownloadUrl, $tempPath)) {
            $output->writeln('<error>Failed to download the update.</error>');
            @unlink($tempPath);
            return Command::FAILURE;
        }

        $permissions = fileperms($pharPath);
        if ($permissions !== false) {
            chmod($tempPath, $permissions);
        }

        if (!rename($tempPath, $pharPath)) {
            $output->writeln('<error>Failed to replace the PHAR file.</error>');
            @unlink($tempPath);
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Successfully updated to version %s.</info>', $result->latestVersion));
        return Command::SUCCESS;
    }

    protected function getPharPath(): string
    {
        return \Phar::running(false);
    }

    private function createUpdateChecker(): UpdateChecker
    {
        $configManager = new ConfigManager();
        return new UpdateChecker(
            $configManager->getConfigDir(),
            Application::VERSION,
            0,
        );
    }

    /**
     * @codeCoverageIgnore
     */
    private function downloadFile(string $url, string $destination): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'header' => "User-Agent: fiquela-cli\r\n",
                'follow_location' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false || strlen($content) === 0) {
            return false;
        }

        return file_put_contents($destination, $content) !== false;
    }
}
