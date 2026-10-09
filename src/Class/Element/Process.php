<?php

namespace Wexample\PhpBpmn\Class\Element;

/**
 * A process: its nodes and flows, and the lanes saying who does what.
 */
class Process extends AbstractElement
{
    private FlowContainer $flow;

    /** @var list<Lane> */
    private array $lanes = [];

    private ?string $laneSetId = null;

    public function __construct(
        string $id,
        ?string $name = null,
        private bool $executable = true,
    ) {
        parent::__construct($id, $name);

        $this->flow = new FlowContainer();
    }

    public function getFlow(): FlowContainer
    {
        return $this->flow;
    }

    public function isExecutable(): bool
    {
        return $this->executable;
    }

    public function setExecutable(bool $executable): static
    {
        $this->executable = $executable;

        return $this;
    }

    /**
     * The top lanes; each may hold lanes.
     *
     * @return list<Lane>
     */
    public function getLanes(): array
    {
        return $this->lanes;
    }

    public function addLane(Lane $lane): Lane
    {
        $this->lanes[] = $lane;

        return $lane;
    }

    public function getLaneSetId(): ?string
    {
        return $this->laneSetId;
    }

    public function setLaneSetId(?string $laneSetId): static
    {
        $this->laneSetId = $laneSetId;

        return $this;
    }

    /**
     * Every lane, nested ones too, top first.
     *
     * @return list<Lane>
     */
    public function getAllLanes(): array
    {
        $all = [];
        $walk = static function (array $lanes) use (&$walk, &$all): void {
            foreach ($lanes as $lane) {
                $all[] = $lane;
                $walk($lane->getChildren());
            }
        };
        $walk($this->lanes);

        return $all;
    }

    public function getLane(string $id): ?Lane
    {
        foreach ($this->getAllLanes() as $lane) {
            if ($lane->getId() === $id) {
                return $lane;
            }
        }

        return null;
    }

    /**
     * The innermost lane holding the node — a boundary event is in the lane
     * of its activity, when none holds it.
     */
    public function getLaneOf(string $nodeId): ?Lane
    {
        $found = null;

        foreach ($this->getAllLanes() as $lane) {
            if (in_array($nodeId, $lane->getNodeIds(), true)) {
                $found = $lane;
            }
        }

        if ($found === null) {
            $node = $this->flow->getNode($nodeId);

            if ($node instanceof Event && $node->getAttachedTo() !== null) {
                return $this->getLaneOf($node->getAttachedTo());
            }
        }

        return $found;
    }
}
