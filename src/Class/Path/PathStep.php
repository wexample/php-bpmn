<?php

namespace Wexample\PhpBpmn\Class\Path;

/**
 * One node a path goes through: who does it — its lane —, and the flow it
 * was reached by, with that flow's condition.
 */
final readonly class PathStep
{
    public function __construct(
        public string $nodeId,
        public string $element,
        public ?string $name,
        public ?string $laneId,
        public ?string $flowId = null,
        public ?string $condition = null,
    ) {
    }
}
