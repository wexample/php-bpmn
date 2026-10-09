<?php

namespace Wexample\PhpBpmn\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\PhpBpmn\Class\Builder\ProcessBuilder;
use Wexample\PhpBpmn\Class\Path\Path;
use Wexample\PhpBpmn\Class\Path\PathFinder;
use Wexample\PhpBpmn\Class\Xml\BpmnXmlReader;
use Wexample\PhpBpmn\Tests\Fixtures\ExampleProcesses;

class PathFinderTest extends TestCase
{
    public function testEachWayOutOfAChoiceIsAPath(): void
    {
        $process = ExampleProcesses::maintenanceRequest()->getProcess('maintenance_request');
        $paths = (new PathFinder(maxVisits: 1))->find($process);

        $complete = array_values(array_filter($paths, static fn (Path $path) => $path->isComplete()));
        $ends = array_map(static fn (Path $path) => implode(',', $path->getEnds()), $complete);
        sort($ends);

        // Above 200 €: refused or repaired, each with the landlord answering
        // or reminded first; below: repaired.
        $this->assertSame(['refused', 'repaired', 'repaired'], $ends);

        $below = array_values(array_filter($complete, static fn (Path $path) => in_array('agent_decides', $path->getNodeIds(), true)))[0];
        $this->assertSame(['tenant', 'agent', 'platform'], $below->getLanes());
        $this->assertSame([['quote_amount', 'flow_quote_amount_agent_decides', null]], $below->getChoices());
    }

    public function testAParallelSplitIsJoinedInOnePath(): void
    {
        $process = ExampleProcesses::tenantMoveIn()->getProcess('tenant_move_in');
        $paths = (new PathFinder(maxVisits: 1))->find($process);

        $straight = array_values(array_filter($paths, static fn (Path $path) => $path->isComplete()))[0];
        $ids = $straight->getNodeIds();

        // Both branches done, the join passed once, then the rest.
        $this->assertContains('send_lease', $ids);
        $this->assertContains('pay_deposit', $ids);
        $this->assertSame(1, count(array_keys($ids, 'lease_and_deposit_done', true)));
        $this->assertSame('moved_in', end($ids));
        $this->assertLessThan(array_search('call_for_funds', $ids, true), array_search('lease_signed_by_all', $ids, true));
    }

    public function testABoundaryTimerIsAWayOfItsActivity(): void
    {
        $process = ExampleProcesses::articleReview()->getProcess('article_review');
        $paths = (new PathFinder(maxVisits: 2))->find($process);

        $reminded = array_filter($paths, static fn (Path $path) => in_array('review_late', $path->getNodeIds(), true) && $path->isComplete());
        $this->assertNotEmpty($reminded);

        // Looping back is bounded: some paths stop, incomplete.
        $this->assertNotEmpty(array_filter($paths, static fn (Path $path) => ! $path->isComplete()));
    }

    public function testAnEventBasedGatewayOffersEachEvent(): void
    {
        $process = (new BpmnXmlReader())->readFile(__DIR__ . '/../Resources/bpmn-io-order.bpmn')->getProcess('Process_order');
        $paths = (new PathFinder())->find($process);

        $this->assertSame(['End_shipped', 'End_cancelled'], array_map(static fn (Path $path) => $path->getEnds()[0], $paths));
        $this->assertSame('Gateway_paid → Flow_4', $paths[1]->describe());
    }

    public function testATerminateEndStopsEveryToken(): void
    {
        $definitions = ProcessBuilder::create('p')
            ->startEvent('start')
            ->parallelGateway('split')
            ->task('slow')
            ->endEvent('done')
            ->from('split')
            ->endEvent('abort', definition: \Wexample\PhpBpmn\Class\Element\EventDefinition::terminate())
            ->build();

        [$path] = (new PathFinder())->find($definitions->getProcess('p'));

        // `slow` was reached first, then `abort` stopped the rest.
        $this->assertSame(['start', 'split', 'slow', 'abort'], $path->getNodeIds());
    }
}
