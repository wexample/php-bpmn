<?php

namespace Wexample\PhpBpmn\Class\Xml;

use DOMDocument;
use DOMElement;
use Wexample\PhpBpmn\Class\Diagram\Bounds;
use Wexample\PhpBpmn\Class\Diagram\Diagram;
use Wexample\PhpBpmn\Class\Element\AbstractElement;
use Wexample\PhpBpmn\Class\Element\CallActivity;
use Wexample\PhpBpmn\Class\Element\Collaboration;
use Wexample\PhpBpmn\Class\Element\Definitions;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Class\Element\EventDefinition;
use Wexample\PhpBpmn\Class\Element\FlowContainer;
use Wexample\PhpBpmn\Class\Element\Gateway;
use Wexample\PhpBpmn\Class\Element\Lane;
use Wexample\PhpBpmn\Class\Element\MessageFlow;
use Wexample\PhpBpmn\Class\Element\Participant;
use Wexample\PhpBpmn\Class\Element\Process;
use Wexample\PhpBpmn\Class\Element\RootReference;
use Wexample\PhpBpmn\Class\Element\SequenceFlow;
use Wexample\PhpBpmn\Class\Element\SubProcess;
use Wexample\PhpBpmn\Class\Element\Task;
use Wexample\PhpBpmn\Enum\EventDefinitionType;
use Wexample\PhpBpmn\Enum\EventPosition;
use Wexample\PhpBpmn\Enum\GatewayType;
use Wexample\PhpBpmn\Enum\TaskType;
use Wexample\PhpBpmn\Enum\TimerKind;
use Wexample\PhpBpmn\Exception\BpmnModelException;
use Wexample\PhpBpmn\Exception\BpmnParseException;

/**
 * Reads a BPMN 2.0 XML document — as bpmn.io, Camunda Modeler or Signavio
 * write them — into Definitions. What is not modelled here is listed on the
 * Definitions, never silently lost.
 */
class BpmnXmlReader
{
    /** Elements read without a place in the model, and not worth reporting. */
    private const array SILENT = ['documentation', 'extensionElements', 'incoming', 'outgoing', 'laneSet', 'conditionExpression'];

    public function readFile(string $path): Definitions
    {
        if (! is_file($path)) {
            throw new BpmnParseException(sprintf('No BPMN file at "%s".', $path));
        }

        return $this->read((string) file_get_contents($path));
    }

    public function read(string $xml): Definitions
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                $error = libxml_get_last_error();

                throw new BpmnParseException(sprintf('Not well-formed XML: %s', $error ? trim($error->message) : 'unknown error'));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        if ($root === null || $root->namespaceURI !== BpmnNamespace::MODEL || $root->localName !== 'definitions') {
            throw new BpmnParseException('Not a BPMN 2.0 document: its root is no bpmn:definitions.');
        }

        $definitions = new Definitions(
            $root->getAttribute('id') ?: 'Definitions',
            $root->getAttribute('targetNamespace') ?: Definitions::DEFAULT_NAMESPACE,
            $root->getAttribute('exporter') ?: null,
            $root->getAttribute('exporterVersion') ?: null,
        );

        try {
            foreach ($this->children($root) as $element) {
                match ($element->localName) {
                    'process' => $definitions->addProcess($this->readProcess($element, $definitions)),
                    'collaboration' => $definitions->addCollaboration($this->readCollaboration($element)),
                    'message', 'signal', 'error', 'escalation' => $definitions->addReference(new RootReference(
                        $element->getAttribute('id'),
                        $element->getAttribute('name') ?: null,
                        $element->localName,
                        $element->getAttribute('errorCode') ?: ($element->getAttribute('escalationCode') ?: null),
                    )),
                    default => $definitions->addUnmodelled($element->localName, $element->getAttribute('id') ?: null),
                };
            }

            foreach ($root->getElementsByTagNameNS(BpmnNamespace::BPMNDI, 'BPMNDiagram') as $diagram) {
                $definitions->addDiagram($this->readDiagram($diagram));
            }
        } catch (BpmnModelException $exception) {
            throw new BpmnParseException($exception->getMessage(), 0, $exception);
        }

        return $definitions;
    }

    private function readProcess(DOMElement $element, Definitions $definitions): Process
    {
        $process = new Process(
            $element->getAttribute('id'),
            $element->getAttribute('name') ?: null,
            $element->getAttribute('isExecutable') !== 'false',
        );
        $this->readCommon($element, $process);

        foreach ($this->children($element, 'laneSet') as $laneSet) {
            $process->setLaneSetId($laneSet->getAttribute('id') ?: null);

            foreach ($this->children($laneSet, 'lane') as $lane) {
                $process->addLane($this->readLane($lane));
            }
        }

        $this->readFlow($element, $process->getFlow(), $definitions);

        return $process;
    }

    private function readLane(DOMElement $element): Lane
    {
        $lane = new Lane($element->getAttribute('id'), $element->getAttribute('name') ?: null);
        $this->readCommon($element, $lane);

        foreach ($this->children($element, 'flowNodeRef') as $ref) {
            $lane->addNode(trim($ref->textContent));
        }

        foreach ($this->children($element, 'childLaneSet') as $childSet) {
            foreach ($this->children($childSet, 'lane') as $child) {
                $lane->addChild($this->readLane($child));
            }
        }

        return $lane;
    }

    private function readFlow(DOMElement $parent, FlowContainer $flow, Definitions $definitions): void
    {
        foreach ($this->children($parent) as $element) {
            $name = $element->localName;
            $id = $element->getAttribute('id');
            $label = $element->getAttribute('name') ?: null;

            if ($type = TaskType::tryFrom($name)) {
                $task = new Task($id, $label, $type);
                $task->setMessageRef($element->getAttribute('messageRef') ?: null);
                $flow->addNode($this->readCommon($element, $task));
            } elseif ($type = GatewayType::tryFrom($name)) {
                $gateway = new Gateway($id, $label, $type);
                $gateway->setDefaultFlow($element->getAttribute('default') ?: null);
                $flow->addNode($this->readCommon($element, $gateway));
            } elseif ($position = EventPosition::tryFrom($name)) {
                $flow->addNode($this->readCommon($element, $this->readEvent($element, $position)));
            } elseif ($name === 'callActivity') {
                $flow->addNode($this->readCommon($element, new CallActivity($id, $label, $element->getAttribute('calledElement') ?: null)));
            } elseif ($name === 'subProcess') {
                $subProcess = new SubProcess($id, $label);
                $this->readFlow($element, $subProcess->getFlow(), $definitions);
                $flow->addNode($this->readCommon($element, $subProcess));
            } elseif ($name === 'sequenceFlow') {
                $condition = $this->children($element, 'conditionExpression')[0] ?? null;
                $flow->addFlow($this->readCommon($element, new SequenceFlow(
                    $id,
                    $element->getAttribute('sourceRef'),
                    $element->getAttribute('targetRef'),
                    $condition ? trim($condition->textContent) : null,
                    $label,
                )));
            } elseif (! in_array($name, self::SILENT, true)) {
                $definitions->addUnmodelled($name, $id ?: null);
            }
        }
    }

    private function readEvent(DOMElement $element, EventPosition $position): Event
    {
        $event = new Event($element->getAttribute('id'), $element->getAttribute('name') ?: null, $position);

        if ($position === EventPosition::BOUNDARY) {
            $event->setAttachedTo($element->getAttribute('attachedToRef') ?: null, $element->getAttribute('cancelActivity') !== 'false');
        }

        foreach ($this->children($element) as $child) {
            $type = EventDefinitionType::tryFrom($child->localName);

            if ($type === null) {
                continue;
            }

            $id = $child->getAttribute('id') ?: null;

            $event->addDefinition(match ($type) {
                EventDefinitionType::MESSAGE => new EventDefinition($type, $child->getAttribute('messageRef') ?: null, id: $id),
                EventDefinitionType::SIGNAL => new EventDefinition($type, $child->getAttribute('signalRef') ?: null, id: $id),
                EventDefinitionType::ERROR => new EventDefinition($type, $child->getAttribute('errorRef') ?: null, id: $id),
                EventDefinitionType::ESCALATION => new EventDefinition($type, $child->getAttribute('escalationRef') ?: null, id: $id),
                EventDefinitionType::LINK => new EventDefinition($type, $child->getAttribute('name') ?: null, id: $id),
                EventDefinitionType::TIMER => $this->readTimer($child, $id),
                EventDefinitionType::CONDITIONAL => new EventDefinition(
                    $type,
                    expression: trim(($this->children($child, 'condition')[0] ?? null)?->textContent ?? '') ?: null,
                    id: $id
                ),
                default => new EventDefinition($type, id: $id),
            });
        }

        return $event;
    }

    private function readTimer(DOMElement $element, ?string $id): EventDefinition
    {
        foreach (TimerKind::cases() as $kind) {
            $value = $this->children($element, $kind->value)[0] ?? null;

            if ($value !== null) {
                return new EventDefinition(EventDefinitionType::TIMER, expression: trim($value->textContent), timerKind: $kind, id: $id);
            }
        }

        return new EventDefinition(EventDefinitionType::TIMER, id: $id);
    }

    private function readCollaboration(DOMElement $element): Collaboration
    {
        $collaboration = new Collaboration($element->getAttribute('id'), $element->getAttribute('name') ?: null);
        $this->readCommon($element, $collaboration);

        foreach ($this->children($element, 'participant') as $participant) {
            $collaboration->addParticipant($this->readCommon($participant, new Participant(
                $participant->getAttribute('id'),
                $participant->getAttribute('name') ?: null,
                $participant->getAttribute('processRef') ?: null,
            )));
        }

        foreach ($this->children($element, 'messageFlow') as $flow) {
            $collaboration->addMessageFlow($this->readCommon($flow, new MessageFlow(
                $flow->getAttribute('id'),
                $flow->getAttribute('sourceRef'),
                $flow->getAttribute('targetRef'),
                $flow->getAttribute('name') ?: null,
                $flow->getAttribute('messageRef') ?: null,
            )));
        }

        return $collaboration;
    }

    private function readDiagram(DOMElement $element): Diagram
    {
        $plane = $element->getElementsByTagNameNS(BpmnNamespace::BPMNDI, 'BPMNPlane')->item(0);
        $diagram = new Diagram($element->getAttribute('id') ?: null, $plane?->getAttribute('bpmnElement') ?: null);

        if (! $plane instanceof DOMElement) {
            return $diagram;
        }

        foreach ($plane->childNodes as $item) {
            if (! $item instanceof DOMElement || $item->namespaceURI !== BpmnNamespace::BPMNDI) {
                continue;
            }

            $elementId = $item->getAttribute('bpmnElement');

            if ($item->localName === 'BPMNShape') {
                $bounds = $item->getElementsByTagNameNS(BpmnNamespace::DC, 'Bounds')->item(0);

                if ($bounds instanceof DOMElement) {
                    $diagram->setShape(
                        $elementId,
                        new Bounds((float) $bounds->getAttribute('x'), (float) $bounds->getAttribute('y'), (float) $bounds->getAttribute('width'), (float) $bounds->getAttribute('height')),
                        $item->hasAttribute('isHorizontal') ? $item->getAttribute('isHorizontal') === 'true' : null,
                        $item->hasAttribute('isExpanded') ? $item->getAttribute('isExpanded') === 'true' : null,
                    );
                }
            } elseif ($item->localName === 'BPMNEdge') {
                $points = [];

                foreach ($item->getElementsByTagNameNS(BpmnNamespace::DI, 'waypoint') as $point) {
                    $points[] = [(float) $point->getAttribute('x'), (float) $point->getAttribute('y')];
                }

                $diagram->setEdge($elementId, $points);
            }
        }

        return $diagram;
    }

    /**
     * Its documentation and our properties.
     *
     * @template T of AbstractElement
     *
     * @param T $target
     *
     * @return T
     */
    private function readCommon(DOMElement $element, AbstractElement $target): AbstractElement
    {
        $documentation = $this->children($element, 'documentation')[0] ?? null;

        if ($documentation !== null) {
            $target->setDocumentation(trim($documentation->textContent));
        }

        foreach ($this->children($element, 'extensionElements') as $extensions) {
            foreach ($extensions->getElementsByTagNameNS(BpmnNamespace::WEXAMPLE, 'property') as $property) {
                $target->setProperty($property->getAttribute('name'), $property->getAttribute('value'));
            }
        }

        return $target;
    }

    /**
     * The BPMN children of an element, of one local name when given.
     *
     * @return list<DOMElement>
     */
    private function children(DOMElement $element, ?string $localName = null): array
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement
                && $child->namespaceURI === BpmnNamespace::MODEL
                && ($localName === null || $child->localName === $localName)) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
