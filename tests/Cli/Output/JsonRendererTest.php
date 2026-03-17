<?php

namespace Cli\Output;

use FQL\Cli\Output\JsonRenderer;
use FQL\Cli\Query\QueryResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class JsonRendererTest extends TestCase
{
    private JsonRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new JsonRenderer();
    }

    public function testRenderEmptyResult(): void
    {
        $output = new BufferedOutput();
        $result = new QueryResult([], [], 0, 0.1);

        $this->renderer->render($output, $result);

        $this->assertEquals("[]" . PHP_EOL, $output->fetch());
    }

    public function testRenderWithData(): void
    {
        $output = new BufferedOutput();
        $data = [
            ['title' => 'Item 1', 'price' => 100],
            ['title' => 'Item 2', 'price' => 200],
        ];
        $result = new QueryResult($data, ['title', 'price'], 2, 0.05);

        $this->renderer->render($output, $result);

        $rendered = trim($output->fetch());
        $decoded = json_decode($rendered, true);

        $this->assertCount(2, $decoded);
        $this->assertEquals('Item 1', $decoded[0]['title']);
        $this->assertEquals(200, $decoded[1]['price']);
    }

    public function testRenderCompactJson(): void
    {
        $output = new BufferedOutput();
        $data = [['a' => 1]];
        $result = new QueryResult($data, ['a'], 1, 0.01);

        $this->renderer->render($output, $result);

        $rendered = trim($output->fetch());
        // Should be compact (no pretty-print)
        $this->assertStringNotContainsString("\n", $rendered);
        $this->assertEquals('[{"a":1}]', $rendered);
    }

    public function testRenderWithUnicodeCharacters(): void
    {
        $output = new BufferedOutput();
        $data = [['name' => 'Příliš žluťoučký kůň']];
        $result = new QueryResult($data, ['name'], 1, 0.01);

        $this->renderer->render($output, $result);

        $rendered = trim($output->fetch());
        // Should not escape unicode
        $this->assertStringContainsString('Příliš žluťoučký kůň', $rendered);
    }

    public function testRenderWithSlashesInData(): void
    {
        $output = new BufferedOutput();
        $data = [['path' => '/usr/local/bin']];
        $result = new QueryResult($data, ['path'], 1, 0.01);

        $this->renderer->render($output, $result);

        $rendered = trim($output->fetch());
        // Should not escape slashes
        $this->assertStringContainsString('/usr/local/bin', $rendered);
    }

    public function testRenderWithUnencodableData(): void
    {
        $output = new BufferedOutput();
        // Create data with invalid UTF-8 that will cause json_encode to fail
        $data = [['bad' => "\xB1\x31"]];
        $result = new QueryResult($data, ['bad'], 1, 0.01);

        $this->renderer->render($output, $result);

        $rendered = trim($output->fetch());
        // Should fall back to []
        $this->assertEquals('[]', $rendered);
    }
}
