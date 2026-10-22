<?php

namespace Wexample\PhpBpmn\Class\Diagram;

/**
 * How a model is drawn (BPMNDI): a shape per element, a line of points per
 * flow. Kept as it was read, written back as it is: a model drawn with
 * another tool keeps its layout here.
 */
class Diagram
{
    /** @var array<string, Bounds> */
    private array $shapes = [];

    /** @var array<string, array{bool|null, bool|null}> horizontal, expanded */
    private array $shapeFlags = [];

    /** @var array<string, list<array{float, float}>> */
    private array $edges = [];

    public function __construct(
        private ?string $id = null,
        private ?string $planeElement = null,
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /** The process or collaboration the diagram draws. */
    public function getPlaneElement(): ?string
    {
        return $this->planeElement;
    }

    public function setPlaneElement(?string $planeElement): static
    {
        $this->planeElement = $planeElement;

        return $this;
    }

    public function setShape(string $elementId, Bounds $bounds, ?bool $horizontal = null, ?bool $expanded = null): static
    {
        $this->shapes[$elementId] = $bounds;
        $this->shapeFlags[$elementId] = [$horizontal, $expanded];

        return $this;
    }

    public function getShape(string $elementId): ?Bounds
    {
        return $this->shapes[$elementId] ?? null;
    }

    /**
     * @return array<string, Bounds>
     */
    public function getShapes(): array
    {
        return $this->shapes;
    }

    /**
     * @return array{bool|null, bool|null}
     */
    public function getShapeFlags(string $elementId): array
    {
        return $this->shapeFlags[$elementId] ?? [null, null];
    }

    /**
     * @param list<array{float, float}> $points
     */
    public function setEdge(string $elementId, array $points): static
    {
        $this->edges[$elementId] = $points;

        return $this;
    }

    /**
     * @return list<array{float, float}>|null
     */
    public function getEdge(string $elementId): ?array
    {
        return $this->edges[$elementId] ?? null;
    }

    /**
     * @return array<string, list<array{float, float}>>
     */
    public function getEdges(): array
    {
        return $this->edges;
    }

    public function isEmpty(): bool
    {
        return ! $this->shapes && ! $this->edges;
    }
}
