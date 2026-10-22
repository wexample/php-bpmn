<?php

namespace Wexample\PhpBpmn\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\PhpBpmn\Class\Xml\BpmnXmlWriter;
use Wexample\PhpBpmn\Tests\Fixtures\ExampleProcesses;

/**
 * `examples/*.bpmn` are what ExampleProcesses build: rewritten with
 * BPMN_WRITE_EXAMPLES=1, compared otherwise.
 */
class ExamplesTest extends TestCase
{
    public function testTheExampleFilesAreUpToDate(): void
    {
        $writer = new BpmnXmlWriter();
        $directory = __DIR__ . '/../../examples';

        foreach (ExampleProcesses::all() as $name => $definitions) {
            $path = $directory . '/' . $name . '.bpmn';
            $xml = $writer->write($definitions);

            if (getenv('BPMN_WRITE_EXAMPLES')) {
                file_put_contents($path, $xml);
            }

            $this->assertFileExists($path);
            $this->assertSame($xml, file_get_contents($path), $name . ' is outdated: run the tests with BPMN_WRITE_EXAMPLES=1.');
        }
    }
}
