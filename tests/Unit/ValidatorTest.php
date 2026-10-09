<?php

namespace Wexample\PhpBpmn\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wexample\PhpBpmn\Class\Builder\ProcessBuilder;
use Wexample\PhpBpmn\Class\Element\Definitions;
use Wexample\PhpBpmn\Class\Validation\BpmnValidator;
use Wexample\PhpBpmn\Class\Xml\BpmnXmlReader;
use Wexample\PhpBpmn\Tests\Fixtures\ExampleProcesses;

class ValidatorTest extends TestCase
{
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
    public function testTheExamplesAreSoundAndRunnable(Definitions $definitions): void
    {
        $report = (new BpmnValidator())->validate($definitions);

        $this->assertSame([], array_map('strval', $report->getIssues()));
    }

    public function testAChoiceWithoutConditionIsAnError(): void
    {
        $definitions = ProcessBuilder::create('p')
            ->startEvent('start')
            ->exclusiveGateway('choice')
            ->endEvent('a')
            ->from('choice')
            ->endEvent('b')
            ->build();

        $report = (new BpmnValidator())->validate($definitions);

        $this->assertTrue($report->has('split_without_condition', 'flow_choice_a'));
        $this->assertTrue($report->has('split_without_condition', 'flow_choice_b'));
    }

    public function testStructuralFaultsAreFound(): void
    {
        $definitions = (new BpmnXmlReader())->read(<<<'XML'
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" id="d">
  <bpmn:process id="p">
    <bpmn:laneSet><bpmn:lane id="l"><bpmn:flowNodeRef>start</bpmn:flowNodeRef><bpmn:flowNodeRef>ghost</bpmn:flowNodeRef></bpmn:lane></bpmn:laneSet>
    <bpmn:startEvent id="start"/>
    <bpmn:task id="orphan"/>
    <bpmn:intermediateCatchEvent id="wait"><bpmn:timerEventDefinition><bpmn:timeDuration>three days</bpmn:timeDuration></bpmn:timerEventDefinition></bpmn:intermediateCatchEvent>
    <bpmn:inclusiveGateway id="incl" default="nowhere"/>
    <bpmn:endEvent id="end"/>
    <bpmn:sequenceFlow id="f1" sourceRef="start" targetRef="wait"/>
    <bpmn:sequenceFlow id="f2" sourceRef="wait" targetRef="incl"/>
    <bpmn:sequenceFlow id="f3" sourceRef="incl" targetRef="end"/>
    <bpmn:sequenceFlow id="f4" sourceRef="incl" targetRef="missing"/>
  </bpmn:process>
</bpmn:definitions>
XML);

        $report = (new BpmnValidator())->validate($definitions);

        $this->assertTrue($report->has('lane_unknown_node', 'l'));
        $this->assertTrue($report->has('unreachable_node', 'orphan'));
        $this->assertTrue($report->has('no_incoming_flow', 'orphan'));
        $this->assertTrue($report->has('timer_invalid', 'wait'));
        $this->assertTrue($report->has('default_not_outgoing', 'incl'));
        $this->assertTrue($report->has('unsupported_gateway', 'incl'));
        $this->assertTrue($report->has('flow_unknown_node', 'f4'));
        $this->assertTrue($report->has('node_without_lane', 'orphan'));
        $this->assertFalse($report->isValid());
    }

    public function testAnEventBasedGatewayLeadsToEventsWaitedFor(): void
    {
        $definitions = ProcessBuilder::create('p')
            ->startEvent('start')
            ->eventBasedGateway('first')
            ->userTask('not_an_event')
            ->endEvent('end')
            ->build();

        $this->assertTrue((new BpmnValidator())->validate($definitions)->has('event_gateway_target', 'flow_first_not_an_event'));
    }
}
