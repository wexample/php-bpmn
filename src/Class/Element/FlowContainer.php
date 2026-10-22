<?php

namespace Wexample\PhpBpmn\Class\Element;

use Wexample\PhpBpmn\Enum\EventPosition;
use Wexample\PhpBpmn\Exception\BpmnModelException;

/**
 * Nodes and the flows between them: what a process holds, and a sub-process
 * too. Nodes and flows keep their order of declaration.
 */
class FlowContainer
{
    /** @var array<string, AbstractFlowNode> */
    private array $nodes = [];

    /** @var array<string, SequenceFlow> */
    private array $flows = [];

    /**
     * @template T of AbstractFlowNode
     *
     * @param T $node
     *
     * @return T
     */
    public function addNode(AbstractFlowNode $node): AbstractFlowNode
    {
        if (isset($this->nodes[$node->getId()]) || isset($this->flows[$node->getId()])) {
            throw new BpmnModelException(sprintf('The id "%s" is used twice.', $node->getId()));
        }

        $this->nodes[$node->getId()] = $node;

        return $node;
    }

    public function addFlow(SequenceFlow $flow): SequenceFlow
    {
        if (isset($this->nodes[$flow->getId()]) || isset($this->flows[$flow->getId()])) {
            throw new BpmnModelException(sprintf('The id "%s" is used twice.', $flow->getId()));
        }

        $this->flows[$flow->getId()] = $flow;

        return $flow;
    }

    /**
     * @return array<string, AbstractFlowNode>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function getNode(string $id): ?AbstractFlowNode
    {
        return $this->nodes[$id] ?? null;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /**
     * @template T of AbstractFlowNode
     *
     * @param class-string<T> $class
     *
     * @return array<string, T>
     */
    public function getNodesOf(string $class): array
    {
        return array_filter($this->nodes, static fn (AbstractFlowNode $node) => $node instanceof $class);
    }

    /**
     * @return array<string, SequenceFlow>
     */
    public function getFlows(): array
    {
        return $this->flows;
    }

    public function getFlow(string $id): ?SequenceFlow
    {
        return $this->flows[$id] ?? null;
    }

    /**
     * @return list<SequenceFlow>
     */
    public function getIncoming(string $nodeId): array
    {
        return array_values(array_filter($this->flows, static fn (SequenceFlow $flow) => $flow->getTarget() === $nodeId));
    }

    /**
     * @return list<SequenceFlow>
     */
    public function getOutgoing(string $nodeId): array
    {
        return array_values(array_filter($this->flows, static fn (SequenceFlow $flow) => $flow->getSource() === $nodeId));
    }

    /**
     * The boundary events attached to an activity.
     *
     * @return list<Event>
     */
    public function getBoundaryEvents(string $activityId): array
    {
        return array_values(array_filter(
            $this->getNodesOf(Event::class),
            static fn (Event $event) => $event->getAttachedTo() === $activityId
        ));
    }

    /**
     * @return list<Event>
     */
    public function getStartEvents(): array
    {
        return array_values(array_filter(
            $this->getNodesOf(Event::class),
            static fn (Event $event) => $event->getPosition() === EventPosition::START
        ));
    }
}
