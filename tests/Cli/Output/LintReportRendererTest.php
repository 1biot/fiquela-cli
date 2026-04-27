<?php

namespace Cli\Output;

use FQL\Cli\Output\LintReportRenderer;
use FQL\Sql\Lint\LintIssue;
use FQL\Sql\Lint\LintReport;
use FQL\Sql\Lint\Severity;
use FQL\Sql\Token\Position;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class LintReportRendererTest extends TestCase
{
    public function testRenderEmptyReportShowsNoIssuesMessage(): void
    {
        $output = new BufferedOutput();
        (new LintReportRenderer())->render($output, new LintReport());

        $this->assertStringContainsString('no issues', $output->fetch());
    }

    public function testRenderErrorIssue(): void
    {
        $output = new BufferedOutput();
        $report = new LintReport([
            new LintIssue(Severity::ERROR, 'unknown-function', 'oops', new Position(0, 1, 5)),
        ]);

        (new LintReportRenderer())->render($output, $report);
        $text = $output->fetch();

        $this->assertStringContainsString('ERROR', $text);
        $this->assertStringContainsString('unknown-function', $text);
        $this->assertStringContainsString('oops', $text);
        $this->assertStringContainsString('line 1', $text);
    }

    public function testRenderWarningAndInfoSeverities(): void
    {
        $output = new BufferedOutput();
        $report = new LintReport([
            new LintIssue(Severity::WARNING, 'missing-from', 'no FROM'),
            new LintIssue(Severity::INFO, 'style', 'consider this'),
        ]);

        (new LintReportRenderer())->render($output, $report);
        $text = $output->fetch();

        $this->assertStringContainsString('WARNING', $text);
        $this->assertStringContainsString('INFO', $text);
        $this->assertStringContainsString('missing-from', $text);
        $this->assertStringContainsString('style', $text);
    }
}
