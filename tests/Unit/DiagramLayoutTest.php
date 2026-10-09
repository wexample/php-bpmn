<?php

namespace Wexample\PhpBpmn\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\PhpBpmn\Class\Diagram\Bounds;
use Wexample\PhpBpmn\Class\Diagram\DiagramLayout;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Tests\Fixtures\ExampleProcesses;

/**
 * Every example drawn from scratch: all of it placed, nothing on top of
 * anything, each node in its lane, the flows read left to right.
 */
class DiagramLayoutTest extends TestCase
{
    public function testEveryExampleIsDrawnWhole(): void
    {
        $layout = new DiagramLayout();

        foreach (ExampleProcesses::all() as $name => $definitions) {
            foreach ($definitions->getProcesses() as $process) {
                $diagram = $layout->layout($process);
                $flow = $process->getFlow();

                foreach ($flow->getNodes() as $id => $node) {
                    $this->assertNotNull($diagram->getShape($id), "$name: node $id is not drawn.");
                }

                foreach ($process->getAllLanes() as $lane) {
                    $this->assertNotNull($diagram->getShape($lane->getId()), "$name: lane {$lane->getId()} is not drawn.");
                }

                foreach ($flow->getFlows() as $id => $sequenceFlow) {
                    $this->assertGreaterThanOrEqual(2, count($diagram->getEdge($id) ?? []), "$name: flow $id is not drawn.");
                }
            }
        }
    }

    public function testNodesNeitherOverlapNorLeaveTheirLane(): void
    {
        $layout = new DiagramLayout();

        foreach (ExampleProcesses::all() as $name => $definitions) {
            foreach ($definitions->getProcesses() as $process) {
                $diagram = $layout->layout($process);
                $placed = [];

                foreach ($process->getFlow()->getNodes() as $id => $node) {
                    $shape = $diagram->getShape($id);

                    // A boundary event sits on its activity on purpose.
                    if ($node instanceof Event && $node->getAttachedTo() !== null) {
                        continue;
                    }

                    foreach ($placed as $otherId => $other) {
                        $this->assertFalse($this->overlap($shape, $other), "$name: $id is drawn over $otherId.");
                    }

                    $placed[$id] = $shape;
                    $lane = $process->getLaneOf($id);

                    if ($lane !== null) {
                        $band = $diagram->getShape($lane->getId());
                        $this->assertGreaterThanOrEqual($band->y, $shape->y, "$name: $id stands above its lane.");
                        $this->assertLessThanOrEqual($band->y + $band->height, $shape->y + $shape->height, "$name: $id runs under its lane.");
                    }
                }
            }
        }
    }

    public function testAFlowForwardReadsLeftToRight(): void
    {
        $layout = new DiagramLayout();
        $definitions = ExampleProcesses::articleReview();
        $process = $definitions->getProcesses()[array_key_first($definitions->getProcesses())];
        $diagram = $layout->layout($process);
        $starts = $process->getFlow()->getStartEvents();
        $start = $diagram->getShape(reset($starts)->getId());

        foreach ($process->getFlow()->getNodes() as $id => $node) {
            $this->assertGreaterThanOrEqual($start->x, $diagram->getShape($id)->x, "$id stands before the start.");
        }
    }

    public function testTheSameProcessGivesTheSameDrawing(): void
    {
        $layout = new DiagramLayout();
        $definitions = ExampleProcesses::engagementLetter();
        $process = $definitions->getProcesses()[array_key_first($definitions->getProcesses())];

        $this->assertEquals($layout->layout($process), $layout->layout($process));
    }

    private function overlap(Bounds $a, Bounds $b): bool
    {
        return $a->x < $b->x + $b->width && $b->x < $a->x + $a->width
            && $a->y < $b->y + $b->height && $b->y < $a->y + $a->height;
    }
}
