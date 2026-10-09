<?php

namespace Wexample\PhpBpmn\Class\Path;

/**
 * One way through a process, from a start to its ends: the nodes in the
 * order they are done — the branches of a parallel split one after the
 * other —, and the choice made at each split. A story, each step told by
 * its lane: what a multi-actor test plays.
 */
class Path
{
    /**
     * @param list<PathStep>                       $steps
     * @param list<array{string, string, ?string}> $choices gateway or activity, flow taken, its condition
     * @param bool                                 $complete false where the walk stopped on a loop or a bound
     */
    public function __construct(
        private readonly array $steps,
        private readonly array $choices,
        private readonly bool $complete,
    ) {
    }

    /**
     * @return list<PathStep>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<string>
     */
    public function getNodeIds(): array
    {
        return array_map(static fn (PathStep $step) => $step->nodeId, $this->steps);
    }

    /**
     * The steps of one kind of element: `userTask`, `endEvent`.
     *
     * @return list<PathStep>
     */
    public function getStepsOf(string ...$elements): array
    {
        return array_values(array_filter($this->steps, static fn (PathStep $step) => in_array($step->element, $elements, true)));
    }

    /**
     * @return list<array{string, string, ?string}>
     */
    public function getChoices(): array
    {
        return $this->choices;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * The end events reached.
     *
     * @return list<string>
     */
    public function getEnds(): array
    {
        return array_map(static fn (PathStep $step) => $step->nodeId, $this->getStepsOf('endEvent'));
    }

    /**
     * The lanes taking part, in their order of appearance.
     *
     * @return list<string>
     */
    public function getLanes(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (PathStep $step) => $step->laneId, $this->steps))));
    }

    /**
     * A label for the path: the choices it made.
     */
    public function describe(): string
    {
        if (! $this->choices) {
            return 'straight';
        }

        return implode(', ', array_map(
            static fn (array $choice) => $choice[0] . ' → ' . ($choice[2] ?? $choice[1]),
            $this->choices
        ));
    }
}
