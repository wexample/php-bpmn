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
        $this->assertSame([['quote_amount', 'flow_quote_amount_agent_decides', 'default']], $below->getChoices());
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
        $this->assertSame('Gateway_paid → Catch_timeout', $paths[1]->describe());
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        foreach (glob(__DIR__ . '/../../examples/*.bpmn') as $file) {
            yield basename($file, '.bpmn') => [$file];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('examples')]
    public function testCoveringPathsTakeEveryFlowOfEachExample(string $file): void
    {
        foreach ((new BpmnXmlReader())->readFile($file)->getProcesses() as $process) {
            $flow = $process->getFlow();
            $paths = (new PathFinder())->cover($process);
            $taken = [];

            foreach ($paths as $path) {
                $this->assertTrue($path->isComplete(), $path->describe());

                foreach ($path->getSteps() as $step) {
                    $taken[$step->flowId ?? ''] = true;
                    // A join keeps the last way in: the others came too.
                    if ($step->element === 'parallelGateway') {
                        foreach ($flow->getIncoming($step->nodeId) as $in) {
                            $taken[$in->getId()] = true;
                        }
                    }
                }
                foreach ($path->getChoices() as $choice) {
                    $taken[$choice[1]] = true;
                }
            }

            $flows = array_map(static fn ($sequenceFlow) => $sequenceFlow->getId(), $flow->getFlows());
            $this->assertSame([], array_values(array_diff($flows, array_keys($taken))), $process->getId());

            $labels = array_map(static fn (Path $path) => $path->describe(), $paths);
            $this->assertSame($labels, array_values(array_unique($labels)));
        }
    }

    public function testCoveringPathsGoRoundEachLoopOnceWithoutEveryCombination(): void
    {
        $process = (new BpmnXmlReader())->readFile(__DIR__ . '/../../examples/public-expenditure.bpmn')->getProcess('public_expenditure');

        $all = (new PathFinder())->find($process);
        $covering = (new PathFinder())->cover($process);

        $this->assertGreaterThanOrEqual(40, count($all));
        $this->assertLessThanOrEqual(12, count($covering));

        // The invoice disputed, then sent again: the loop is in a path.
        $looping = array_filter($covering, static fn (Path $path) => count(array_keys($path->getNodeIds(), 'match_invoice', true)) === 2);
        $this->assertNotEmpty($looping);
    }

    public function testCoveringPathsAreNamedByWhatTheyChoseFirst(): void
    {
        $process = ExampleProcesses::articleReview()->getProcess('article_review');
        $labels = array_map(static fn (Path $path) => $path->describe(), (new PathFinder())->cover($process));

        $this->assertSame(['main: published', 'review → review_late ↺', 'verdict → default ↺'], $labels);
    }
}

