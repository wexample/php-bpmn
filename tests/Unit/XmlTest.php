<?php

namespace Wexample\PhpBpmn\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wexample\PhpBpmn\Class\Element\Definitions;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Class\Element\Gateway;
use Wexample\PhpBpmn\Class\Element\Task;
use Wexample\PhpBpmn\Class\Validation\BpmnValidator;
use Wexample\PhpBpmn\Class\Xml\BpmnXmlReader;
use Wexample\PhpBpmn\Class\Xml\BpmnXmlWriter;
use Wexample\PhpBpmn\Enum\EventDefinitionType;
use Wexample\PhpBpmn\Enum\GatewayType;
use Wexample\PhpBpmn\Enum\TaskType;
use Wexample\PhpBpmn\Enum\TimerKind;
use Wexample\PhpBpmn\Exception\BpmnParseException;
use Wexample\PhpBpmn\Tests\Fixtures\ExampleProcesses;

class XmlTest extends TestCase
{
    public function testADocumentOfAnotherToolIsRead(): void
    {
        $definitions = (new BpmnXmlReader())->readFile(__DIR__ . '/../Resources/bpmn-io-order.bpmn');

        $process = $definitions->getProcess('Process_order');
        $this->assertSame('Order', $process->getName());
        $this->assertSame('An order, paid then shipped.', $process->getDocumentation());
        $this->assertSame(['Lane_sales', 'Lane_warehouse'], array_map(static fn ($lane) => $lane->getId(), $process->getLanes()));
        $this->assertSame('Lane_warehouse', $process->getLaneOf('Task_ship')->getId());

        $confirm = $process->getFlow()->getNode('Task_confirm');
        $this->assertInstanceOf(Task::class, $confirm);
        $this->assertSame(TaskType::SEND, $confirm->getType());

        $gateway = $process->getFlow()->getNode('Gateway_paid');
        $this->assertInstanceOf(Gateway::class, $gateway);
        $this->assertSame(GatewayType::EVENT_BASED, $gateway->getType());

        $timeout = $process->getFlow()->getNode('Catch_timeout');
        $this->assertInstanceOf(Event::class, $timeout);
        $timer = $timeout->getDefinition(EventDefinitionType::TIMER);
        $this->assertSame(TimerKind::DURATION, $timer->getTimerKind());
        $this->assertSame('P3D', $timer->getExpression());

        $this->assertTrue($process->getFlow()->getNode('End_cancelled')->is(EventDefinitionType::TERMINATE));
        $this->assertSame('Message_paid', $process->getFlow()->getNode('Catch_paid')->getDefinition()->getRef());

        $collaboration = $definitions->getCollaborations()['Collaboration_1'];
        $this->assertCount(2, $collaboration->getParticipants());
        $this->assertSame('Task_confirm', $collaboration->getMessageFlows()[0]->getSource());

        $diagram = $definitions->getDiagrams()[0];
        $this->assertSame(270.0, $diagram->getShape('Task_confirm')->x);
        $this->assertSame([[218.0, 120.0], [270.0, 120.0]], $diagram->getEdge('Flow_1'));
        $this->assertSame([true, null], $diagram->getShapeFlags('Participant_shop'));

        // The data objects: not modelled, said so.
        $this->assertSame([['dataObjectReference', 'DataObjectReference_invoice'], ['dataObject', 'DataObject_invoice']], $definitions->getUnmodelled());
        $report = (new BpmnValidator())->validate($definitions);
        $this->assertTrue($report->isValid(), implode("\n", $report->getErrors()));
        $this->assertTrue($report->has('unmodelled_element', 'DataObject_invoice'));
    }

    public function testWritingThenReadingKeepsTheModelAndItsDiagram(): void
    {
        $reader = new BpmnXmlReader();
        $writer = new BpmnXmlWriter();
        $original = $reader->readFile(__DIR__ . '/../Resources/bpmn-io-order.bpmn');

        $xml = $writer->write($original);
        $again = $reader->read($xml);

        $this->assertSame($xml, $writer->write($again));
        $this->assertSame('bpmn-js (https://demo.bpmn.io)', $again->getExporter());
        $this->assertSame([[218.0, 120.0], [270.0, 120.0]], $again->getDiagrams()[0]->getEdge('Flow_1'));
        $this->assertSame('Payment received', $again->getReference('Message_paid')->getName());
    }

    /**
     * @return iterable<string, array{Definitions}>
     */
    public static function provideExamples(): iterable
    {
        foreach (ExampleProcesses::all() as $name => $definitions) {
            yield $name => [$definitions];
        }
    }

    #[DataProvider('provideExamples')]
    public function testAProcessBuiltInCodeIsWrittenAndReadBack(Definitions $definitions): void
    {
        $writer = new BpmnXmlWriter();
        $xml = $writer->write($definitions);
        $read = (new BpmnXmlReader())->read($xml);

        $this->assertSame($xml, $writer->write($read));

        foreach ($definitions->getProcesses() as $id => $process) {
            $readProcess = $read->getProcess($id);
            $this->assertSame(array_keys($process->getFlow()->getNodes()), array_keys($readProcess->getFlow()->getNodes()));

            foreach ($process->getFlow()->getFlows() as $flowId => $flow) {
                $this->assertSame($flow->getCondition(), $readProcess->getFlow()->getFlow($flowId)->getCondition());
            }

            foreach ($process->getFlow()->getNodes() as $nodeId => $node) {
                $this->assertSame($node->getProperties(), $readProcess->getFlow()->getNode($nodeId)->getProperties(), $nodeId);
            }

            foreach ($process->getAllLanes() as $lane) {
                $this->assertSame($lane->getProperties(), $readProcess->getLane($lane->getId())->getProperties());
            }
        }
    }

    public function testWhatIsNoBpmnIsRefused(): void
    {
        $this->expectException(BpmnParseException::class);

        (new BpmnXmlReader())->read('<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    public function testBrokenXmlIsRefused(): void
    {
        $this->expectException(BpmnParseException::class);
        $this->expectExceptionMessage('Not well-formed XML');

        (new BpmnXmlReader())->read('<bpmn:definitions');
    }

    public function testAnIdUsedTwiceIsRefused(): void
    {
        $this->expectException(BpmnParseException::class);
        $this->expectExceptionMessage('"Task_1" is used twice');

        (new BpmnXmlReader())->read('<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" id="d"><bpmn:process id="p"><bpmn:task id="Task_1"/><bpmn:task id="Task_1"/></bpmn:process></bpmn:definitions>');
    }
}
