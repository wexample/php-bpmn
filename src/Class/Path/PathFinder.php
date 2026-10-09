<?php

namespace Wexample\PhpBpmn\Class\Path;

use Wexample\PhpBpmn\Class\Element\AbstractFlowNode;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Class\Element\Gateway;
use Wexample\PhpBpmn\Class\Element\Process;
use Wexample\PhpBpmn\Class\Element\SequenceFlow;
use Wexample\PhpBpmn\Enum\EventDefinitionType;
use Wexample\PhpBpmn\Enum\EventPosition;
use Wexample\PhpBpmn\Enum\GatewayType;

/**
 * Every way through a process, walked token by token:
 *
 * - an exclusive, inclusive or event-based gateway, each of its ways in turn;
 * - an activity with boundary events, its normal end and each event in turn;
 * - a parallel gateway, all its ways in one path, joined before going on;
 * - a loop, gone round `maxVisits` times at most per node: past it, the path
 *   stops, incomplete.
 *
 * Inclusive gateways are walked as exclusive ones: one way each.
 */
class PathFinder
{
    public function __construct(
        private readonly int $maxVisits = 2,
        private readonly int $maxPaths = 200,
    ) {
    }

    /**
     * @return list<Path>
     */
    public function find(Process $process, ?string $startId = null): array
    {
        $paths = [];
        $flow = $process->getFlow();

        foreach ($flow->getStartEvents() as $start) {
            if ($startId !== null && $start->getId() !== $startId) {
                continue;
            }

            $this->walk($process, [[$start->getId(), null]], [], [], [], [], $paths);
        }

        return $paths;
    }

    /**
     * @param list<array{string, ?SequenceFlow}>   $tokens  where tokens stand, and the flow that brought each
     * @param list<PathStep>                       $steps
     * @param list<array{string, string, string}> $choices
     * @param array<string, int>                   $visits
     * @param array<string, int>                   $arrivals tokens arrived at each parallel join
     * @param list<Path>                           $paths
     */
    private function walk(Process $process, array $tokens, array $steps, array $choices, array $visits, array $arrivals, array &$paths): void
    {
        $flow = $process->getFlow();

        while ($tokens) {
            if (count($paths) >= $this->maxPaths) {
                return;
            }

            [$nodeId, $via] = array_shift($tokens);
            $node = $flow->getNode($nodeId);

            if ($node === null) {
                continue;
            }

            // A parallel join waits until every way in has arrived.
            if ($node instanceof Gateway && $node->getType() === GatewayType::PARALLEL && count($flow->getIncoming($nodeId)) > 1) {
                $arrivals[$nodeId] = ($arrivals[$nodeId] ?? 0) + 1;

                if ($arrivals[$nodeId] < count($flow->getIncoming($nodeId))) {
                    continue;
                }

                $arrivals[$nodeId] = 0;
            }

            $visits[$nodeId] = ($visits[$nodeId] ?? 0) + 1;

            if ($visits[$nodeId] > $this->maxVisits) {
                $paths[] = new Path($steps, $choices, false);

                return;
            }

            $steps[] = $this->step($process, $node, $via);
            $outgoing = $flow->getOutgoing($nodeId);

            if ($node instanceof Event && $node->getPosition() === EventPosition::END) {
                if ($node->is(EventDefinitionType::TERMINATE)) {
                    $tokens = [];
                }

                continue;
            }

            $alternatives = $this->getAlternatives($process, $node, $outgoing);

            if ($alternatives !== null) {
                foreach ($alternatives as [$choiceNode, $target, $choiceFlow, $extraSteps, $label]) {
                    $this->walk(
                        $process,
                        [...$tokens, [$target, $choiceFlow]],
                        [...$steps, ...$extraSteps],
                        [...$choices, [$choiceNode, $choiceFlow?->getId() ?? $target, $label]],
                        $visits,
                        $arrivals,
                        $paths
                    );
                }

                return;
            }

            foreach ($outgoing as $flowOut) {
                $tokens[] = [$flowOut->getTarget(), $flowOut];
            }
        }

        $paths[] = new Path($steps, $choices, true);
    }

    /**
     * Where the walk forks: a choice of gateway, or an activity some
     * boundary event may interrupt. Null: no fork, every way taken.
     *
     * @param list<SequenceFlow> $outgoing
     *
     * @return list<array{string, string, ?SequenceFlow, list<PathStep>, string}>|null node, target, flow, steps added, what the choice was
     */
    private function getAlternatives(Process $process, AbstractFlowNode $node, array $outgoing): ?array
    {
        $flow = $process->getFlow();

        if ($node instanceof Gateway && in_array($node->getType(), [GatewayType::EXCLUSIVE, GatewayType::INCLUSIVE, GatewayType::EVENT_BASED, GatewayType::COMPLEX], true) && count($outgoing) > 1) {
            return array_map(static fn (SequenceFlow $flowOut) => [
                $node->getId(),
                $flowOut->getTarget(),
                $flowOut,
                [],
                $flowOut->getCondition() ?? ($flowOut->getId() === $node->getDefaultFlow() ? 'default' : $flowOut->getTarget()),
            ], $outgoing);
        }

        $boundaries = array_filter($flow->getBoundaryEvents($node->getId()), static fn (Event $event) => $event->isCancelActivity());

        if (! $boundaries) {
            return null;
        }

        // Done normally, or interrupted by one of its events.
        $alternatives = [];

        foreach ($outgoing as $flowOut) {
            $alternatives[] = [$node->getId(), $flowOut->getTarget(), $flowOut, [], 'completed'];
        }

        foreach ($boundaries as $boundary) {
            foreach ($flow->getOutgoing($boundary->getId()) as $flowOut) {
                $alternatives[] = [$node->getId(), $flowOut->getTarget(), $flowOut, [$this->step($process, $boundary, null)], $boundary->getId()];
            }
        }

        return $alternatives;
    }

    private function step(Process $process, AbstractFlowNode $node, ?SequenceFlow $via): PathStep
    {
        return new PathStep(
            $node->getId(),
            $node->getElementName(),
            $node->getName(),
            $process->getLaneOf($node->getId())?->getId(),
            $via?->getId(),
            $via?->getCondition(),
        );
    }
}
