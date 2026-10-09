<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * Who does the nodes it holds: a role, a team, the application. A lane may
 * hold lanes of its own.
 */
class Lane extends AbstractElement
{
    /** @var list<string> */
    private array $nodeIds = [];

    /** @var list<Lane> */
    private array $children = [];

    /**
     * @return list<string>
     */
    public function getNodeIds(): array
    {
        return $this->nodeIds;
    }

    public function addNode(string $nodeId): static
    {
        if (! in_array($nodeId, $this->nodeIds, true)) {
            $this->nodeIds[] = $nodeId;
        }

        return $this;
    }

    public function removeNode(string $nodeId): static
    {
        $this->nodeIds = array_values(array_diff($this->nodeIds, [$nodeId]));

        return $this;
    }

    /**
     * @return list<Lane>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function addChild(Lane $lane): static
    {
        $this->children[] = $lane;

        return $this;
    }
}
