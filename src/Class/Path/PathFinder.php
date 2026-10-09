<?php

namespace Wexample\PhpBpmn\Class\Path;

use Closure;
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
     * A few paths that, together, take every flow once at least — each loop
     * gone round once —, rather than every combination of them: what a
     * person reads, and what a test plays, on a large process.
     *
     * Each path is walked choosing, at every fork, a way not taken yet; else
     * the way towards the nearest flow not taken yet; else the shortest way
     * to an end — and once it has gone round a loop, the shortest way to an
     * end only, so it ends. Paths are drawn until a new one takes no new
     * flow. Each is complete, and labelled by the choices it was the first
     * to make.
     *
     * @return list<Path>
     */
    public function cover(Process $process, ?string $startId = null): array
    {
        // One round more than a loop needs: the way out of it.
        $walker = new self($this->maxVisits + 1, $this->maxPaths);
        $graph = $this->graph($process);
        $joins = $this->joins($process);
        $toEnd = $this->distancesToEnd($process, $graph);
        $taken = [];
        $covering = [];
        $labels = [];

        while (count($covering) < $this->maxPaths) {
            $choose = function (array $alternatives, array $steps, array $choices, array $visits) use ($graph, $joins, $toEnd, &$taken): int {
                $takenHere = $taken + array_fill_keys($this->flowsOf($steps, $choices, $joins), true);
                $leaving = $visits && max($visits) > 1;
                $best = 0;
                $bestScore = PHP_INT_MAX;

                foreach ($alternatives as $index => [, $target, $flow]) {
                    $flowId = $flow?->getId();
                    // A node gone round as often as allowed ends the path, incomplete.
                    $spent = ($visits[$target] ?? 0) >= $this->maxVisits;

                    $score = match (true) {
                        $spent => PHP_INT_MAX - 1,
                        $leaving => $toEnd[$target] ?? 1_000_000,
                        $flowId !== null && ! isset($takenHere[$flowId]) => 0,
                        default => ($near = $this->distanceToUntaken($graph, $target, $takenHere)) !== null
                            ? 1 + $near
                            : 1_000_000 + ($toEnd[$target] ?? 1_000_000),
                    };

                    if ($score < $bestScore) {
                        [$best, $bestScore] = [$index, $score];
                    }
                }

                return $best;
            };

            $found = [];
            foreach ($process->getFlow()->getStartEvents() as $start) {
                if ($startId === null || $start->getId() === $startId) {
                    $walker->walk($process, [[$start->getId(), null]], [], [], [], [], $found, $choose);
                }
            }

            $new = [];
            foreach ($found as $path) {
                if (! $path->isComplete()) {
                    continue;
                }

                $flows = $this->flowsOf($path->getSteps(), $path->getChoices(), $joins);
                $fresh = array_diff($flows, array_keys($taken));

                if (! $fresh) {
                    continue;
                }

                $taken += array_fill_keys($flows, true);
                $new[] = $path;
                $covering[] = new Path($path->getSteps(), $path->getChoices(), true, $this->label($path, $fresh, $labels));
            }

            if (! $new) {
                break;
            }
        }

        return $covering;
    }

    /**
     * The flows a walk went along: those that brought each step, and those
     * its choices took.
     *
     * @param list<PathStep>                       $steps
     * @param list<array{string, string, string}> $choices
     * @param array<string, list<string>>          $joins   parallel join → its incoming flows
     *
     * @return list<string>
     */
    private function flowsOf(array $steps, array $choices, array $joins = []): array
    {
        $flows = [
            ...array_map(static fn (PathStep $step) => $step->flowId, $steps),
            ...array_map(static fn (array $choice) => $choice[1], $choices),
        ];

        // A join keeps the flow of the last way in only: every one came.
        foreach ($steps as $step) {
            array_push($flows, ...($joins[$step->nodeId] ?? []));
        }

        return array_values(array_unique(array_filter($flows)));
    }

    /**
     * Named by the choices it made first — `↺` where one goes back —, the
     * first path by its ends.
     *
     * @param list<string>          $fresh
     * @param array<string, true>   $labels the labels given, kept unique
     */
    private function label(Path $path, array $fresh, array &$labels): string
    {
        $parts = [];

        foreach ($path->getChoices() as [$node, $flowId, $label]) {
            if (in_array($flowId, $fresh, true)) {
                $parts[] = $node . ' → ' . $label . ($this->goesBack($path, $flowId) ? ' ↺' : '');
            }
        }

        // Its loops first: what it is there to show. The rest past three is
        // in its steps.
        usort($parts, static fn (string $a, string $b) => str_ends_with($b, '↺') <=> str_ends_with($a, '↺'));
        $label = $labels === []
            ? 'main: ' . implode(', ', $path->getEnds())
            : (implode(', ', array_slice($parts, 0, 3)) . (count($parts) > 3 ? ', …' : '') ?: implode(', ', $path->getEnds()));

        $unique = $label;
        for ($i = 2; isset($labels[$unique]); $i++) {
            $unique = $label . ' (' . $i . ')';
        }
        $labels[$unique] = true;

        return $unique;
    }

    /**
     * Whether a choice of the path leads back: after its flow, before the
     * next fork, the path meets a node it met before.
     */
    private function goesBack(Path $path, string $flowId): bool
    {
        $forks = array_flip(array_map(static fn (array $choice) => $choice[0], $path->getChoices()));
        $met = [];
        $after = false;

        foreach ($path->getSteps() as $step) {
            if (! $after) {
                if ($step->flowId !== $flowId) {
                    $met[$step->nodeId] = true;

                    continue;
                }

                $after = true;
            }

            if (isset($met[$step->nodeId])) {
                return true;
            }

            if (isset($forks[$step->nodeId])) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>> each parallel join, its incoming flows
     */
    private function joins(Process $process): array
    {
        $flow = $process->getFlow();
        $joins = [];

        foreach ($flow->getNodes() as $node) {
            $incoming = $flow->getIncoming($node->getId());

            if ($node instanceof Gateway && $node->getType() === GatewayType::PARALLEL && count($incoming) > 1) {
                $joins[$node->getId()] = array_map(static fn (SequenceFlow $in) => $in->getId(), $incoming);
            }
        }

        return $joins;
    }

    /**
     * Node → the flows leaving it, an activity's boundary events' included.
     *
     * @return array<string, list<array{string, string}>> flow id, target
     */
    private function graph(Process $process): array
    {
        $flow = $process->getFlow();
        $graph = [];

        foreach ($flow->getNodes() as $node) {
            $id = $node->getId();
            $graph[$id] ??= [];

            foreach ($flow->getOutgoing($id) as $out) {
                $graph[$id][] = [$out->getId(), $out->getTarget()];
            }

            foreach ($flow->getBoundaryEvents($id) as $boundary) {
                foreach ($flow->getOutgoing($boundary->getId()) as $out) {
                    $graph[$id][] = [$out->getId(), $out->getTarget()];
                }
            }
        }

        return $graph;
    }

    /**
     * How many flows from a node to its nearest end.
     *
     * @return array<string, int>
     */
    private function distancesToEnd(Process $process, array $graph): array
    {
        $reverse = [];
        foreach ($graph as $source => $edges) {
            foreach ($edges as [, $target]) {
                $reverse[$target][] = $source;
            }
        }

        $distances = [];
        $queue = [];
        foreach ($process->getFlow()->getNodes() as $node) {
            if ($node instanceof Event && $node->getPosition() === EventPosition::END) {
                $distances[$node->getId()] = 0;
                $queue[] = $node->getId();
            }
        }

        while ($queue) {
            $id = array_shift($queue);
            foreach ($reverse[$id] ?? [] as $source) {
                if (! isset($distances[$source])) {
                    $distances[$source] = $distances[$id] + 1;
                    $queue[] = $source;
                }
            }
        }

        return $distances;
    }

    /**
     * How many flows from a node to the nearest flow not taken yet; null
     * when every flow it reaches is taken.
     *
     * @param array<string, true> $taken
     */
    private function distanceToUntaken(array $graph, string $from, array $taken): ?int
    {
        $distances = [$from => 0];
        $queue = [$from];

        while ($queue) {
            $id = array_shift($queue);
            foreach ($graph[$id] ?? [] as [$flowId, $target]) {
                if (! isset($taken[$flowId])) {
                    return $distances[$id];
                }

                if (! isset($distances[$target])) {
                    $distances[$target] = $distances[$id] + 1;
                    $queue[] = $target;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{string, ?SequenceFlow}>   $tokens  where tokens stand, and the flow that brought each
     * @param list<PathStep>                       $steps
     * @param list<array{string, string, string}> $choices
     * @param array<string, int>                   $visits
     * @param array<string, int>                   $arrivals tokens arrived at each parallel join
     * @param list<Path>                           $paths
     * @param Closure|null                         $choose  where only one way is wanted at a fork: picks its index
     */
    private function walk(Process $process, array $tokens, array $steps, array $choices, array $visits, array $arrivals, array &$paths, ?Closure $choose = null): void
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
                if ($choose) {
                    $alternatives = [$alternatives[$choose($alternatives, $steps, $choices, $visits)]];
                }

                foreach ($alternatives as [$choiceNode, $target, $choiceFlow, $extraSteps, $label]) {
                    $this->walk(
                        $process,
                        [...$tokens, [$target, $choiceFlow]],
                        [...$steps, ...$extraSteps],
                        [...$choices, [$choiceNode, $choiceFlow?->getId() ?? $target, $label]],
                        $visits,
                        $arrivals,
                        $paths,
                        $choose
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
