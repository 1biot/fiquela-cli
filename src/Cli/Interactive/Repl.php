<?php

namespace FQL\Cli\Interactive;

use FQL\Cli\Output\LintReportRenderer;
use FQL\Cli\Output\TableRenderer;
use FQL\Cli\Query\ApiQueryExecutor;
use FQL\Cli\Query\LocalQueryExecutor;
use FQL\Cli\Query\QueryExecutorInterface;
use FQL\Cli\Query\QuerySplitter;
use FQL\Cli\Application;
use FQL\Cli\Config\UpdateChecker;
use Symfony\Component\Console\Output\ConsoleOutput;

class Repl
{
    private ConsoleOutput $output;
    private QueryExecutorInterface $executor;
    private HistoryManager $historyManager;
    private ResultPager $resultPager;
    private LintReportRenderer $lintRenderer;
    /** @var callable(string):(string|false) */
    private $lineReader;
    /** @var (callable(string|null): ModeSwitchResult)|null */
    private $connectCallback;
    /** @var (callable(): ModeSwitchResult)|null */
    private $localCallback;
    /** @var (callable(): list<array{name: string, url: string, user: string}>)|null */
    private $serverListCallback;
    private ?UpdateChecker $updateChecker;

    /**
     * @param callable(string|null): ModeSwitchResult $connectCallback
     * @param callable(): ModeSwitchResult $localCallback
     * @param callable(): list<array{name: string, url: string, user: string}> $serverListCallback
     */
    public function __construct(
        ConsoleOutput $output,
        QueryExecutorInterface $executor,
        HistoryManager $historyManager,
        ?ResultPager $resultPager = null,
        ?callable $lineReader = null,
        ?callable $connectCallback = null,
        ?callable $localCallback = null,
        ?UpdateChecker $updateChecker = null,
        ?callable $serverListCallback = null,
    ) {
        $this->output = $output;
        $this->executor = $executor;
        $this->historyManager = $historyManager;
        $this->resultPager = $resultPager ?? new ResultPager(new TableRenderer());
        $this->lintRenderer = new LintReportRenderer();
        $this->lineReader = $lineReader ?? static fn(string $prompt) => readline($prompt);
        $this->connectCallback = $connectCallback;
        $this->localCallback = $localCallback;
        $this->serverListCallback = $serverListCallback;
        $this->updateChecker = $updateChecker;
    }

    /**
     * Start the interactive REPL loop.
     */
    public function run(): int
    {
        $this->loadHistory();
        $this->printWelcomeMessage();
        $this->printUpdateNotification();

        $queryBuffer = '';
        $promptLines = 0;

        while (true) {
            $prompt = empty($queryBuffer) ? 'fql> ' : '  -> ';
            $line = ($this->lineReader)($prompt);

            if ($line === false || strtolower(trim($line)) === 'exit') {
                break;
            }

            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }

            if ($trimmedLine === 'info') {
                $this->printWelcomeMessage();
                continue;
            }

            if ($trimmedLine === 'clear') {
                $this->output->write("\033[2J\033[H");
                $this->printWelcomeMessage();
                $queryBuffer = '';
                $promptLines = 0;
                continue;
            }

            if ($this->handleModeSwitch($trimmedLine)) {
                $queryBuffer = '';
                $promptLines = 0;
                continue;
            }

            $queryBuffer .= ' ' . $trimmedLine;
            $promptLines++;

            // Execute when buffer ends with semicolon (outside of quotes)
            if (QuerySplitter::hasTerminatingSemicolon($queryBuffer)) {
                $queryBuffer = QuerySplitter::stripTrailingSemicolon($queryBuffer);

                if (preg_match('/^\s*lint\s+(.+)$/is', $queryBuffer, $m) === 1) {
                    $this->handleLintCommand($m[1], $promptLines);
                } else {
                    $this->replaceInputWithHighlight($queryBuffer, $promptLines);
                    $this->historyManager->save($queryBuffer);
                    $this->executeQuery($queryBuffer);
                }

                $queryBuffer = '';
                $promptLines = 0;
            }
        }

        return 0;
    }

    /**
     * Clear the user's just-typed prompt lines and reprint the query
     * syntax-highlighted in their place. Keeps the highlighted form in the
     * scrollback while leaving the readline history plain.
     */
    private function replaceInputWithHighlight(string $query, int $linesToClear): void
    {
        if ($linesToClear < 1) {
            return;
        }

        $this->output->write(sprintf("\033[%dA\033[J", $linesToClear));
        $highlighted = rtrim($this->executor->highlightQuery(trim($query)));
        $this->output->writeln('fql> ' . $highlighted . ';');
    }

    private function handleLintCommand(string $query, int $linesToClear): void
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            $this->output->writeln('<comment>Usage: lint &lt;query&gt;;</comment>');
            return;
        }

        if ($linesToClear >= 1) {
            $this->output->write(sprintf("\033[%dA\033[J", $linesToClear));
            $highlighted = rtrim($this->executor->highlightQuery($trimmed));
            $this->output->writeln('fql> <comment>lint</comment> ' . $highlighted . ';');
        }

        try {
            $report = $this->executor->lint($trimmed);
            $this->lintRenderer->render($this->output, $report);
        } catch (\Exception $e) {
            $this->output->writeln(sprintf('<error>Lint failed: %s</error>', $e->getMessage()));
        }
    }

    private function executeQuery(string $query): void
    {
        // Support multiple queries separated by semicolons (respecting quoted strings)
        $queries = QuerySplitter::split($query);

        foreach ($queries as $key => $singleQuery) {
            try {
                if (count($queries) > 1) {
                    $this->output->writeln('');
                    $this->output->writeln(sprintf('<info>Query #%d:</info>', $key + 1));
                }

                $report = $this->executor->lint($singleQuery);
                if (count($report) > 0) {
                    $this->lintRenderer->render($this->output, $report);
                }
                if ($report->hasErrors()) {
                    continue;
                }

                $this->resultPager->display($this->output, $this->executor, $singleQuery);

                if ($this->executor instanceof ApiQueryExecutor) {
                    $this->syncApiHistory();
                }
            } catch (\Exception $e) {
                $this->output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            }
        }
    }

    private function loadHistory(): void
    {
        if ($this->executor instanceof ApiQueryExecutor) {
            $this->syncApiHistory();
        } else {
            $this->historyManager->load();
        }
    }

    private function syncApiHistory(): void
    {
        if (!$this->executor instanceof ApiQueryExecutor) {
            return;
        }

        // For API mode, download history from server
        try {
            $client = $this->executor->getClient();
            $historyEntries = $client->getHistory();

            usort($historyEntries, function ($a, $b): int {
                $aTs = strtotime($a->createdAt) ?: 0;
                $bTs = strtotime($b->createdAt) ?: 0;
                return $aTs <=> $bTs;
            });

            $queries = array_map(fn($entry) => $entry->query, $historyEntries);
            $this->historyManager->replaceWithApiHistory($queries);
        } catch (\Exception) {
            // If history download fails, just load local file
            $this->historyManager->load();
        }
    }

    /**
     * Handle mode switch commands (connect/local).
     *
     * @return bool True if the input was a mode switch command (consumed).
     */
    private function handleModeSwitch(string $input): bool
    {
        $lower = strtolower($input);

        if ($lower === 'local') {
            return $this->switchToLocal();
        }

        if ($lower === 'connect-list' || $lower === 'servers') {
            $this->listServers();
            return true;
        }

        if ($lower === 'connect' || str_starts_with($lower, 'connect ')) {
            $serverName = trim(substr($input, 7)) ?: null;
            return $this->switchToApi($serverName);
        }

        return false;
    }

    private function listServers(): void
    {
        if ($this->serverListCallback === null) {
            $this->output->writeln('<comment>Server list is not available.</comment>');
            return;
        }

        $servers = ($this->serverListCallback)();
        if ($servers === []) {
            $this->output->writeln('<comment>No servers configured. See README for auth.json setup.</comment>');
            return;
        }

        $activeName = $this->executor instanceof ApiQueryExecutor
            ? $this->executor->getServerName()
            : null;

        $this->output->writeln('<info>Configured API servers:</info>');
        foreach ($servers as $s) {
            $marker = ($activeName !== null && $s['name'] === $activeName) ? ' <info>(active)</info>' : '';
            $this->output->writeln(sprintf(
                '  %s — %s (user: %s)%s',
                $s['name'],
                $s['url'],
                $s['user'],
                $marker
            ));
        }
        $this->output->writeln(sprintf('<comment>Use \'connect %s\' to switch.</comment>', '<name>'));
    }

    private function switchToApi(?string $serverName): bool
    {
        if ($this->connectCallback === null) {
            $this->output->writeln('<comment>Mode switching is not available.</comment>');
            return true;
        }

        if ($this->executor instanceof ApiQueryExecutor) {
            $this->output->writeln('<comment>Already in API mode.</comment>');
            return true;
        }

        $result = ($this->connectCallback)($serverName);
        if (!$result->success) {
            $this->output->writeln(sprintf('<error>%s</error>', $result->error ?? 'Failed to connect.'));
            return true;
        }

        /** @var QueryExecutorInterface $executor */
        $executor = $result->executor;
        /** @var HistoryManager $historyManager */
        $historyManager = $result->historyManager;
        $this->executor = $executor;
        $this->historyManager = $historyManager;
        $this->loadHistory();
        $this->printWelcomeMessage();
        return true;
    }

    private function switchToLocal(): bool
    {
        if ($this->localCallback === null) {
            $this->output->writeln('<comment>Mode switching is not available.</comment>');
            return true;
        }

        if ($this->executor instanceof LocalQueryExecutor) {
            $this->output->writeln('<comment>Already in LOCAL mode.</comment>');
            return true;
        }

        $result = ($this->localCallback)();
        if (!$result->success) {
            $this->output->writeln(sprintf('<error>%s</error>', $result->error ?? 'Failed to switch.'));
            return true;
        }

        /** @var QueryExecutorInterface $executor */
        $executor = $result->executor;
        /** @var HistoryManager $historyManager */
        $historyManager = $result->historyManager;
        $this->executor = $executor;
        $this->historyManager = $historyManager;
        $this->loadHistory();
        $this->printWelcomeMessage();
        return true;
    }

    private function printUpdateNotification(): void
    {
        if ($this->updateChecker === null) {
            return;
        }

        $result = $this->updateChecker->check();
        if ($result === null || !$result->updateAvailable) {
            return;
        }

        $section = $this->output->section();
        $section->writeln(sprintf(
            '<comment>A new version is available: %s (current: %s)</comment>',
            $result->latestVersion,
            Application::VERSION,
        ));
        if (\Phar::running(false) !== '') {
            $section->writeln('<comment>Update: fiquela-cli self-update</comment>');
        } else {
            $section->writeln(
                '<comment>Update: curl -fsSL https://raw.githubusercontent.com/1biot/fiquela-cli/main/install.sh | bash</comment>'
            );
        }
        $section->writeln('');
    }

    private function printWelcomeMessage(): void
    {
        $section = $this->output->section();
        $section->writeln(sprintf('FiQueLa CLI v%s', Application::VERSION));
        $section->writeln('');

        if ($this->executor instanceof LocalQueryExecutor) {
            $section->writeln(sprintf('<info>Mode:          </info>LOCAL'));
            $section->writeln(sprintf('<info>Memory limit:  </info>%s', ini_get('memory_limit')));

            $file = $this->executor->getFile();
            if ($file !== null && file_exists($file)) {
                $section->writeln(sprintf('<info>File:          </info>%s', $file));
                $section->writeln(sprintf(
                    '<info>File size:     </info>%s',
                    TableRenderer::humanFilesize(filesize($file) ?: 0)
                ));
                $section->writeln(sprintf('<info>Encoding:      </info>%s', $this->executor->getEncoding()));
                $section->writeln(sprintf('<info>Delimiter:     </info>%s', $this->executor->getDelimiter()));
            } elseif ($file !== null) {
                $section->writeln(sprintf('<error>File not found: %s</error>', $file));
            }
        } elseif ($this->executor instanceof ApiQueryExecutor) {
            $section->writeln(sprintf('<info>Mode:          </info>API'));
            $section->writeln(sprintf(
                '<info>Server:        </info>%s (%s)',
                $this->executor->getClient()->getBaseUrl(),
                $this->executor->getServerName()
            ));
        }

        $section->writeln('');
        $section->writeln("Commands end with ;. Type 'exit' or Ctrl+C to quit.");
        $section->writeln("Type 'connect [server]' to switch to API mode, 'local' to switch to LOCAL mode.");
        $section->writeln("Type 'connect-list' (or 'servers') to list configured API servers.");
        $section->writeln("Type 'lint <query>;' to statically analyse a query without running it.");
    }
}
