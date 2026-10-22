<?php

namespace Wexample\PhpBpmn\Class\Element;

use Wexample\PhpBpmn\Class\Diagram\Diagram;

/**
 * A whole BPMN document: its processes, its collaborations, what events
 * refer to, and how it is drawn.
 */
class Definitions extends AbstractElement
{
    public const string DEFAULT_NAMESPACE = 'https://wexample.com/bpmn';

    /** @var array<string, Process> */
    private array $processes = [];

    /** @var array<string, Collaboration> */
    private array $collaborations = [];

    /** @var array<string, RootReference> */
    private array $references = [];

    /** @var list<Diagram> */
    private array $diagrams = [];

    /**
     * What was read and is not modelled here — a data object, an annotation —
     * by element and id: dropped on writing, reported by the validator.
     *
     * @var list<array{string, ?string}>
     */
    private array $unmodelled = [];

    public function __construct(
        string $id,
        private string $targetNamespace = self::DEFAULT_NAMESPACE,
        private ?string $exporter = null,
        private ?string $exporterVersion = null,
    ) {
        parent::__construct($id);
    }

    public function getTargetNamespace(): string
    {
        return $this->targetNamespace;
    }

    public function getExporter(): ?string
    {
        return $this->exporter;
    }

    public function getExporterVersion(): ?string
    {
        return $this->exporterVersion;
    }

    public function setExporter(?string $exporter, ?string $version = null): static
    {
        $this->exporter = $exporter;
        $this->exporterVersion = $version;

        return $this;
    }

    public function addProcess(Process $process): Process
    {
        $this->processes[$process->getId()] = $process;

        return $process;
    }

    /**
     * @return array<string, Process>
     */
    public function getProcesses(): array
    {
        return $this->processes;
    }

    public function getProcess(string $id): ?Process
    {
        return $this->processes[$id] ?? null;
    }

    public function addCollaboration(Collaboration $collaboration): Collaboration
    {
        $this->collaborations[$collaboration->getId()] = $collaboration;

        return $collaboration;
    }

    /**
     * @return array<string, Collaboration>
     */
    public function getCollaborations(): array
    {
        return $this->collaborations;
    }

    public function addReference(RootReference $reference): RootReference
    {
        $this->references[$reference->getId()] = $reference;

        return $reference;
    }

    /**
     * @return array<string, RootReference>
     */
    public function getReferences(?string $kind = null): array
    {
        return $kind === null
            ? $this->references
            : array_filter($this->references, static fn (RootReference $reference) => $reference->getKind() === $kind);
    }

    public function getReference(string $id): ?RootReference
    {
        return $this->references[$id] ?? null;
    }

    public function addDiagram(Diagram $diagram): Diagram
    {
        $this->diagrams[] = $diagram;

        return $diagram;
    }

    /**
     * @return list<Diagram>
     */
    public function getDiagrams(): array
    {
        return $this->diagrams;
    }

    public function addUnmodelled(string $element, ?string $id): static
    {
        $this->unmodelled[] = [$element, $id];

        return $this;
    }

    /**
     * @return list<array{string, ?string}>
     */
    public function getUnmodelled(): array
    {
        return $this->unmodelled;
    }

    /**
     * The process a node belongs to.
     */
    public function findProcessOf(string $nodeId): ?Process
    {
        foreach ($this->processes as $process) {
            if ($process->getFlow()->hasNode($nodeId)) {
                return $process;
            }
        }

        return null;
    }
}
