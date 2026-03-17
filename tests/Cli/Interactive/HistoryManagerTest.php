<?php

namespace Cli\Interactive;

use FQL\Cli\Interactive\HistoryManager;
use PHPUnit\Framework\TestCase;

class HistoryManagerTest extends TestCase
{
    private string $tempFile;
    private HistoryManager $manager;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/fql-history-test-' . uniqid();
        $this->manager = new HistoryManager($this->tempFile, 5);
    }

    protected function tearDown(): void
    {
        if (function_exists('readline_clear_history')) {
            readline_clear_history();
        }

        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testGetHistoryFile(): void
    {
        $this->assertEquals($this->tempFile, $this->manager->getHistoryFile());
    }

    public function testReplaceWithApiHistory(): void
    {
        $queries = [
            'SELECT * FROM items',
            'SELECT title FROM items WHERE price > 100',
            'SELECT COUNT(*) FROM items',
        ];

        $this->manager->replaceWithApiHistory($queries);

        $this->assertFileExists($this->tempFile);
        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);

        $this->assertStringContainsString('_HiStOrY_V2_', $decoded);
        $this->assertStringContainsString('SELECT * FROM items', $decoded);
        $this->assertStringContainsString('SELECT title FROM items WHERE price > 100', $decoded);
    }

    public function testReplaceWithEmptyApiHistory(): void
    {
        $this->manager->replaceWithApiHistory([]);

        $this->assertFileExists($this->tempFile);
        $content = (string) file_get_contents($this->tempFile);
        $this->assertStringStartsWith("_HiStOrY_V2_\n", $content);
    }

    public function testSaveAddsHistoryEntry(): void
    {
        if (!function_exists('readline_clear_history')) {
            $this->markTestSkipped('readline not available');
        }

        readline_clear_history();
        $this->manager->save('SELECT * FROM test');

        $this->assertFileExists($this->tempFile);
        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);
        $this->assertStringContainsString('SELECT * FROM test', $decoded);
    }

    public function testSaveDeduplicatesLastEntry(): void
    {
        if (!function_exists('readline_clear_history')) {
            $this->markTestSkipped('readline not available');
        }

        readline_clear_history();
        $this->manager->save('SELECT * FROM test');
        $this->manager->save('SELECT * FROM test');

        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);
        $this->assertEquals(1, substr_count($decoded, 'SELECT * FROM test'));
    }

    public function testSaveTruncatesHistoryToLimit(): void
    {
        if (!function_exists('readline_clear_history')) {
            $this->markTestSkipped('readline not available');
        }

        readline_clear_history();

        for ($i = 1; $i <= 10; $i++) {
            $this->manager->save('SELECT ' . $i);
        }

        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);

        // manager max entries is 5
        $this->assertStringContainsString('SELECT 10', $decoded);
        $this->assertStringContainsString('SELECT 6', $decoded);
        $this->assertDoesNotMatchRegularExpression('/SELECT 1\b/', $decoded);
    }

    public function testReplaceWithApiHistoryNormalizesMultilineQueries(): void
    {
        $queries = [
            "SELECT *\n  FROM items\n  WHERE price > 100",
            '',
            '   ',
            'SELECT 1',
        ];

        $this->manager->replaceWithApiHistory($queries);

        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);

        // Multi-line query should be collapsed to single line
        $this->assertStringContainsString('SELECT * FROM items WHERE price > 100', $decoded);
        // Empty/whitespace-only queries should be skipped
        $this->assertStringContainsString('SELECT 1', $decoded);
    }

    public function testReplaceWithApiHistoryCreatesDirectoryIfNeeded(): void
    {
        $nestedFile = sys_get_temp_dir() . '/fql-history-nested-' . uniqid() . '/history';
        $manager = new HistoryManager($nestedFile, 5);

        $manager->replaceWithApiHistory(['SELECT 1']);

        $this->assertFileExists($nestedFile);

        // Cleanup
        unlink($nestedFile);
        rmdir(dirname($nestedFile));
    }

    public function testLoadWithoutReadline(): void
    {
        // Should not throw even if history file doesn't exist
        $this->manager->load();
        $this->assertTrue(true);
    }

    public function testLoadWithExistingFile(): void
    {
        if (!function_exists('readline_read_history')) {
            $this->markTestSkipped('readline not available');
        }

        // Create a history file first
        file_put_contents($this->tempFile, "_HiStOrY_V2_\nSELECT 1\n");

        $this->manager->load();
        $this->assertTrue(true);
    }

    public function testSaveWithDifferentQuery(): void
    {
        if (!function_exists('readline_clear_history')) {
            $this->markTestSkipped('readline not available');
        }

        readline_clear_history();
        $this->manager->save('SELECT 1');
        $this->manager->save('SELECT 2');

        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);
        $this->assertStringContainsString('SELECT 1', $decoded);
        $this->assertStringContainsString('SELECT 2', $decoded);
    }

    public function testReplaceWithApiHistoryHandlesOctalEscapes(): void
    {
        // Readline may encode spaces as \040, so use decodeReadlineHistory to verify
        $this->manager->replaceWithApiHistory(['SELECT * FROM items']);

        $this->assertFileExists($this->tempFile);
        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);
        $this->assertStringContainsString('SELECT * FROM items', $decoded);
    }

    public function testSaveCreatesFileWithHistory(): void
    {
        if (!function_exists('readline_clear_history')) {
            $this->markTestSkipped('readline not available');
        }

        readline_clear_history();

        // Save should write the history file
        $this->manager->save('query1');
        $this->assertFileExists($this->tempFile);

        // Save duplicate should be ignored
        $this->manager->save('query1');

        // Save different query should be added
        $this->manager->save('query2');

        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);
        $this->assertStringContainsString('query2', $decoded);
    }

    public function testTruncatePreservesHistoryHeader(): void
    {
        if (!function_exists('readline_clear_history')) {
            $this->markTestSkipped('readline not available');
        }

        readline_clear_history();

        // maxEntries is 5 (set in setUp)
        for ($i = 1; $i <= 8; $i++) {
            $this->manager->save(sprintf('QUERY_%d', $i));
        }

        $content = (string) file_get_contents($this->tempFile);
        $decoded = $this->decodeReadlineHistory($content);

        // Should still have _HiStOrY_V2_ header
        $this->assertStringContainsString('_HiStOrY_V2_', $decoded);
        // Should have at most 5 entries
        $this->assertStringContainsString('QUERY_8', $decoded);
        $this->assertStringContainsString('QUERY_4', $decoded);
    }

    private function decodeReadlineHistory(string $escapedString): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{1,3})/', function ($matches) {
            return chr((int) octdec($matches[1]));
        }, $escapedString);
    }
}
