<?php

namespace Wexample\PhpBpmn\Class\Diagram;

use Wexample\PhpBpmn\Class\Element\AbstractFlowNode;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Class\Element\Gateway;
use Wexample\PhpBpmn\Class\Element\Lane;
use Wexample\PhpBpmn\Class\Element\Process;

/**
 * A diagram drawn for a process that has none: a file written by the builder,
 * or by a tool that left out BPMNDI.
 *
 * Read left to right, as a process is: each lane a band across the width, the
 * nodes in columns by how far they stand from a start — the longest way to
 * them, so that a node comes after everything leading to it —, nodes of one
 * lane and one column stacked in rows of that lane. Flows leave a node on its
 * right and enter the next on its left, turning at right angles; a flow going
 * back runs under the lanes rather than through them. A boundary event sits on
 * the lower edge of its activity, and what follows it starts a column later.
 *
 * Nothing here is chosen: the same process gives the same drawing, so a layout
 * written into a file and read again is the one shown.
 */
class DiagramLayout
{
    public const float LANE_HEADER = 30;

    public const float COLUMN = 170;

    public const float ROW = 120;

    public const float MARGIN = 20;

    /** How far under the last lane a flow going back runs, and how far apart two such flows are. */
    public const float RETURN_GAP = 24;

    public function layout(Process $process): Diagram
    {
        $diagram = new Diagram($process->getId() . '_di', $process->getId());
        $flow = $process->getFlow();
        $nodes = array_filter($flow->getNodes(), fn (AbstractFlowNode $node): bool => ! $this->isBoundary($node));
        $ranks = $this->rank($process, array_keys($nodes));

        // The bands: the leaf lanes in their order, a band for what no lane
        // holds when lanes exist, a single band when none does.
        $bands = [];
        $leafLanes = array_values(array_filter($process->getAllLanes(), fn (Lane $lane): bool => ! $lane->getChildren()));

        foreach ($leafLanes as $lane) {
            $bands[$lane->getId()] = [];
        }

        $bands[''] = [];

        foreach ($nodes as $id => $node) {
            $lane = $process->getLaneOf($id);
            $bands[$lane && isset($bands[$lane->getId()]) ? $lane->getId() : ''][] = $id;
        }

        if (! $bands['']) {
            unset($bands['']);
        }

        $depth = $this->laneDepth($process);
        $left = self::LANE_HEADER * $depth;
        $columns = max([0, ...array_values($ranks)]) + 1;
        $width = $left + $columns * self::COLUMN;

        // Rows: the nodes of one band and one column, one under the other.
        $top = 0.0;
        $bandBounds = [];

        foreach ($bands as $laneId => $ids) {
            $rows = [];

            foreach ($ids as $id) {
                $rows[$ranks[$id]][] = $id;
            }

            $height = self::ROW * max(1, ...array_map('count', $rows ?: [[]]));

            foreach ($rows as $rank => $cell) {
                foreach (array_values($cell) as $row => $id) {
                    [$w, $h] = $this->size($nodes[$id]);
                    $diagram->setShape($id, new Bounds(
                        $left + $rank * self::COLUMN + (self::COLUMN - $w) / 2,
                        $top + $row * self::ROW + (self::ROW - $h) / 2,
                        $w,
                        $h,
                    ));
                }
            }

            $bandBounds[$laneId] = [$top, $height];
            $top += $height;
        }

        $this->placeLanes($process->getLanes(), $diagram, $bandBounds, 0, $width);
        $this->placeBoundaryEvents($process, $diagram);
        $this->placeEdges($process, $diagram, $ranks, $top);

        return $diagram;
    }

    private function isBoundary(AbstractFlowNode $node): bool
    {
        return $node instanceof Event && $node->getAttachedTo() !== null;
    }

    /**
     * How deep lanes nest: as many header columns are kept on the left.
     */
    private function laneDepth(Process $process): int
    {
        $measure = function (array $lanes) use (&$measure): int {
            $deepest = 0;

            foreach ($lanes as $lane) {
                $deepest = max($deepest, 1 + $measure($lane->getChildren()));
            }

            return $deepest;
        };

        return $measure($process->getLanes());
    }

    /**
     * The column of each node: the longest way to it from a start, the flows
     * going back left out. What follows a boundary event counts from its
     * activity.
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    private function rank(Process $process, array $ids): array
    {
        $flow = $process->getFlow();
        $successors = [];

        foreach ($flow->getFlows() as $sequenceFlow) {
            $source = $flow->getNode($sequenceFlow->getSource());
            $from = $source instanceof Event && $source->getAttachedTo() !== null
                ? $source->getAttachedTo()
                : $sequenceFlow->getSource();
            $successors[$from][] = $sequenceFlow->getTarget();
        }

        // The flows going back: found walking from the starts, a flow to a
        // node still being walked closes a loop.
        $back = [];
        $state = [];
        $walk = function (string $id) use (&$walk, &$state, &$back, $successors): void {
            $state[$id] = 'open';

            foreach ($successors[$id] ?? [] as $next) {
                if (($state[$next] ?? null) === 'open') {
                    $back[$id . '>' . $next] = true;
                } elseif (! isset($state[$next])) {
                    $walk($next);
                }
            }

            $state[$id] = 'done';
        };

        foreach ($ids as $id) {
            if (! $flow->getIncoming($id)) {
                $walk($id);
            }
        }

        foreach ($ids as $id) {
            if (! isset($state[$id])) {
                $walk($id);
            }
        }

        // Longest way, in the order of a topological walk of what is left.
        $incoming = array_fill_keys($ids, 0);

        foreach ($successors as $from => $targets) {
            foreach ($targets as $to) {
                if (! isset($back[$from . '>' . $to]) && isset($incoming[$to])) {
                    $incoming[$to]++;
                }
            }
        }

        $ranks = array_fill_keys($ids, 0);
        $queue = array_keys(array_filter($incoming, fn (int $count): bool => $count === 0));

        while ($queue) {
            $id = array_shift($queue);

            foreach ($successors[$id] ?? [] as $next) {
                if (isset($back[$id . '>' . $next]) || ! isset($incoming[$next])) {
                    continue;
                }

                $ranks[$next] = max($ranks[$next], $ranks[$id] + 1);

                if (--$incoming[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        return $ranks;
    }

    /**
     * @return array{float, float}
     */
    private function size(AbstractFlowNode $node): array
    {
        return match (true) {
            $node instanceof Event => [36.0, 36.0],
            $node instanceof Gateway => [50.0, 50.0],
            default => [120.0, 80.0],
        };
    }

    /**
     * A lane spans the bands of the leaf lanes it holds; a parent keeps a
     * header column on the left of its children.
     *
     * @param list<Lane> $lanes
     * @param array<string, array{float, float}> $bandBounds
     *
     * @return array{float, float}|null top and bottom of what was placed
     */
    private function placeLanes(array $lanes, Diagram $diagram, array $bandBounds, int $depth, float $width): ?array
    {
        $span = null;

        foreach ($lanes as $lane) {
            $extent = $lane->getChildren()
                ? $this->placeLanes($lane->getChildren(), $diagram, $bandBounds, $depth + 1, $width)
                : (isset($bandBounds[$lane->getId()])
                    ? [$bandBounds[$lane->getId()][0], $bandBounds[$lane->getId()][0] + $bandBounds[$lane->getId()][1]]
                    : null);

            if ($extent === null) {
                continue;
            }

            $x = self::LANE_HEADER * $depth;
            $diagram->setShape($lane->getId(), new Bounds($x, $extent[0], $width - $x, $extent[1] - $extent[0]), true);
            $span = $span === null ? $extent : [min($span[0], $extent[0]), max($span[1], $extent[1])];
        }

        return $span;
    }

    private function placeBoundaryEvents(Process $process, Diagram $diagram): void
    {
        $offsets = [];

        foreach ($process->getFlow()->getNodes() as $id => $node) {
            if (! $this->isBoundary($node)) {
                continue;
            }

            $activity = $diagram->getShape($node->getAttachedTo());

            if ($activity === null) {
                continue;
            }

            // Several on one activity stand side by side, from its right end.
            $index = $offsets[$node->getAttachedTo()] = ($offsets[$node->getAttachedTo()] ?? -1) + 1;
            $diagram->setShape($id, new Bounds(
                $activity->x + $activity->width - 30 - $index * 40,
                $activity->y + $activity->height - 18,
                36,
                36,
            ));
        }
    }

    /**
     * @param array<string, int> $ranks
     */
    private function placeEdges(Process $process, Diagram $diagram, array $ranks, float $bottom): void
    {
        $returns = 0;

        foreach ($process->getFlow()->getFlows() as $flow) {
            $from = $diagram->getShape($flow->getSource());
            $to = $diagram->getShape($flow->getTarget());

            if ($from === null || $to === null) {
                continue;
            }

            $source = $process->getFlow()->getNode($flow->getSource());

            if ($source instanceof Event && $source->getAttachedTo() !== null) {
                // Out of the bottom of the boundary event, then on to the next.
                $start = [$from->x + $from->width / 2, $from->y + $from->height];
                $end = [$to->x, $to->y + $to->height / 2];
                $diagram->setEdge($flow->getId(), [$start, [$start[0], $end[1]], $end]);

                continue;
            }

            $start = [$from->x + $from->width, $from->y + $from->height / 2];
            $end = [$to->x, $to->y + $to->height / 2];

            if ($end[0] > $start[0]) {
                $points = $start[1] === $end[1]
                    ? [$start, $end]
                    : [$start, [($start[0] + $end[0]) / 2, $start[1]], [($start[0] + $end[0]) / 2, $end[1]], $end];
            } else {
                // Going back: down under the lanes, along, and up into the
                // node from below.
                $y = $bottom + self::RETURN_GAP * (1 + $returns++);
                $out = [$from->x + $from->width / 2, $from->y + $from->height];
                $in = [$to->x + $to->width / 2, $to->y + $to->height];
                $points = [$out, [$out[0], $y], [$in[0], $y], $in];
            }

            $diagram->setEdge($flow->getId(), $points);
        }
    }
}
