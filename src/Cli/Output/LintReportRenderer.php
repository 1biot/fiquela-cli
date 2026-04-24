<?php

namespace FQL\Cli\Output;

use FQL\Sql\Lint\LintIssue;
use FQL\Sql\Lint\LintReport;
use FQL\Sql\Lint\Severity;
use Symfony\Component\Console\Output\OutputInterface;

class LintReportRenderer
{
    public function render(OutputInterface $output, LintReport $report): void
    {
        if (count($report) === 0) {
            $output->writeln('<info>Lint: no issues found.</info>');
            return;
        }

        foreach ($report as $issue) {
            $output->writeln($this->formatIssue($issue));
        }
    }

    private function formatIssue(LintIssue $issue): string
    {
        $tag = match ($issue->severity) {
            Severity::ERROR => 'error',
            Severity::WARNING => 'comment',
            Severity::INFO => 'info',
        };

        $where = $issue->position !== null ? ' at ' . $issue->position : '';

        return sprintf(
            '<%s>[%s] %s%s</%s> — %s',
            $tag,
            strtoupper($issue->severity->value),
            $issue->rule,
            $where,
            $tag,
            $issue->message
        );
    }
}
