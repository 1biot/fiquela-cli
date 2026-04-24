<?php

namespace Cli\Query;

use FQL\Cli\Query\LocalQueryExecutor;
use FQL\Sql\Lint\Severity;
use PHPUnit\Framework\TestCase;

class LocalQueryExecutorTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/fql-local-executor-' . uniqid() . '.csv';
        file_put_contents($this->tempFile, "id;name\n1;Alice\n2;Bob\n3;Carol\n4;David\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testExecuteWithPagination(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        $result = $executor->execute('SELECT id, name', 1, 2);

        $this->assertEquals(['id', 'name'], $result->headers);
        $this->assertEquals(4, $result->totalCount);
        $this->assertCount(2, $result->data);
        $this->assertTrue($result->hasMorePages);
    }

    public function testExecuteAll(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        $result = $executor->executeAll('SELECT id, name');

        $this->assertCount(4, $result->data);
        $this->assertEquals(4, $result->totalCount);
        $this->assertFalse($result->isEmpty());
    }

    public function testGettersAndModeName(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        $this->assertEquals('LOCAL', $executor->getModeName());
        $this->assertEquals($this->tempFile, $executor->getFile());
        $this->assertEquals('utf-8', $executor->getEncoding());
        $this->assertEquals(';', $executor->getDelimiter());
    }

    public function testLintReturnsEmptyReportForValidQuery(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        $report = $executor->lint('SELECT id, name');

        $this->assertFalse($report->hasErrors());
    }

    public function testLintFlagsSyntaxErrorAsError(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        $report = $executor->lint('SELECT FROM');

        $this->assertTrue($report->hasErrors());
    }

    public function testLintFlagsUnknownFunction(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        $report = $executor->lint('SELECT no_such_fn(id)');

        $hasUnknownFn = false;
        foreach ($report as $issue) {
            if ($issue->severity === Severity::ERROR && str_contains($issue->rule, 'function')) {
                $hasUnknownFn = true;
                break;
            }
        }
        $this->assertTrue($hasUnknownFn);
    }

    public function testLintFlagsMissingSourceFile(): void
    {
        $executor = new LocalQueryExecutor(null, null, ',', 'utf-8');

        $report = $executor->lint('SELECT * FROM csv(/tmp/definitely-missing.csv).*');

        $hasFileNotFound = false;
        foreach ($report as $issue) {
            if ($issue->rule === 'file-not-found') {
                $hasFileNotFound = true;
                break;
            }
        }
        $this->assertTrue($hasFileNotFound);
    }

    public function testHighlightQueryOnSuccess(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        $highlighted = $executor->highlightQuery('SELECT id, name');

        $this->assertIsString($highlighted);
        $this->assertNotSame('', $highlighted);
    }

    public function testHighlightQueryReturnsFormattedOutput(): void
    {
        $executor = new LocalQueryExecutor('/nonexistent/file.csv', 'csv', ';', 'utf-8');

        $query = 'SELECT * FROM *';
        $highlighted = $executor->highlightQuery($query);
        $this->assertIsString($highlighted);
        $this->assertStringContainsString('SELECT', strtoupper($highlighted));
    }

    public function testExecuteWithoutFileUsingFqlProvider(): void
    {
        $executor = new LocalQueryExecutor(null, null, ';', 'utf-8');
        $query = 'SELECT id, name FROM csv(' . $this->tempFile . ', "utf-8", ";").*';

        $result = $executor->executeAll($query);

        $this->assertCount(4, $result->data);
        $this->assertEquals(['id', 'name'], $result->headers);
    }

    public function testExecuteEmptyResult(): void
    {
        $emptyFile = sys_get_temp_dir() . '/fql-local-empty-' . uniqid() . '.csv';
        file_put_contents($emptyFile, "id;name\n");

        try {
            $executor = new LocalQueryExecutor($emptyFile, 'csv', ';', 'utf-8');
            $result = $executor->execute('SELECT id, name WHERE id = 999');

            $this->assertTrue($result->isEmpty());
            $this->assertEquals(0, $result->totalCount);
        } finally {
            unlink($emptyFile);
        }
    }

    public function testExecuteAllEmptyResult(): void
    {
        $emptyFile = sys_get_temp_dir() . '/fql-local-empty-' . uniqid() . '.csv';
        file_put_contents($emptyFile, "id;name\n");

        try {
            $executor = new LocalQueryExecutor($emptyFile, 'csv', ';', 'utf-8');
            $result = $executor->executeAll('SELECT id, name WHERE id = 999');

            $this->assertTrue($result->isEmpty());
            $this->assertEquals(0, $result->totalCount);
        } finally {
            unlink($emptyFile);
        }
    }

    public function testExecuteWithoutPagination(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        // No page/itemsPerPage — should return all results
        $result = $executor->execute('SELECT id, name');

        $this->assertCount(4, $result->data);
        $this->assertEquals(4, $result->totalCount);
        $this->assertFalse($result->hasMorePages);
    }

    public function testExecuteWithPaginationSinglePage(): void
    {
        $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');

        // Page large enough to hold all results
        $result = $executor->execute('SELECT id, name', 1, 100);

        $this->assertCount(4, $result->data);
        $this->assertFalse($result->hasMorePages);
    }

    public function testExecuteWithXmlFile(): void
    {
        $xmlFile = sys_get_temp_dir() . '/fql-local-xml-' . uniqid() . '.xml';
        file_put_contents($xmlFile, '<?xml version="1.0" encoding="UTF-8"?>
<items>
  <item><id>1</id><name>Alice</name></item>
  <item><id>2</id><name>Bob</name></item>
</items>');

        try {
            $executor = new LocalQueryExecutor($xmlFile, 'xml', ',', 'utf-8');
            $result = $executor->executeAll('SELECT id, name FROM items.item');

            $this->assertCount(2, $result->data);
            $this->assertEquals(['id', 'name'], $result->headers);
        } finally {
            unlink($xmlFile);
        }
    }

    public function testGetFileNull(): void
    {
        $executor = new LocalQueryExecutor(null);
        $this->assertNull($executor->getFile());
    }

    public function testGetDefaultValues(): void
    {
        $executor = new LocalQueryExecutor();
        $this->assertEquals('LOCAL', $executor->getModeName());
        $this->assertEquals('utf-8', $executor->getEncoding());
        $this->assertEquals(',', $executor->getDelimiter());
    }

    public function testExecuteWithIntoReturnsFlatData(): void
    {
        $outputFile = sys_get_temp_dir() . '/fql-into-' . uniqid() . '.csv';

        try {
            $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');
            $query = sprintf('SELECT id, name INTO csv(%s)', $outputFile);

            $result = $executor->execute($query);

            $this->assertEquals(
                ['success', 'rows_written', 'file_name', 'file_size'],
                $result->headers
            );
            $this->assertCount(1, $result->data);
            $this->assertSame('ok', $result->data[0]['success']);
            $this->assertEquals(4, $result->data[0]['rows_written']);
            $this->assertEquals(basename($outputFile), $result->data[0]['file_name']);
            $this->assertGreaterThan(0, (int) $result->data[0]['file_size']);
            $this->assertFileExists($outputFile);
        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    public function testExecuteAllWithIntoReturnsFlatData(): void
    {
        $outputFile = sys_get_temp_dir() . '/fql-into-all-' . uniqid() . '.csv';

        try {
            $executor = new LocalQueryExecutor($this->tempFile, 'csv', ';', 'utf-8');
            $query = sprintf('SELECT id, name INTO csv(%s)', $outputFile);

            $result = $executor->executeAll($query);

            $this->assertCount(1, $result->data);
            $this->assertSame('ok', $result->data[0]['success']);
            $this->assertEquals(4, $result->data[0]['rows_written']);
            $this->assertFileExists($outputFile);
        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }
}
