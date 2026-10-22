<?php

namespace Wexample\PhpBpmn\Class\Xml;

use DOMDocument;
use DOMElement;
use Wexample\PhpBpmn\Class\Diagram\Diagram;
use Wexample\PhpBpmn\Class\Element\AbstractElement;
use Wexample\PhpBpmn\Class\Element\AbstractFlowNode;
use Wexample\PhpBpmn\Class\Element\CallActivity;
use Wexample\PhpBpmn\Class\Element\Collaboration;
use Wexample\PhpBpmn\Class\Element\Definitions;
use Wexample\PhpBpmn\Class\Element\Event;
use Wexample\PhpBpmn\Class\Element\EventDefinition;
use Wexample\PhpBpmn\Class\Element\FlowContainer;
use Wexample\PhpBpmn\Class\Element\Gateway;
use Wexample\PhpBpmn\Class\Element\Lane;
use Wexample\PhpBpmn\Class\Element\Process;
use Wexample\PhpBpmn\Class\Element\RootReference;
use Wexample\PhpBpmn\Class\Element\SubProcess;
use Wexample\PhpBpmn\Class\Element\Task;
use Wexample\PhpBpmn\Enum\EventDefinitionType;
use Wexample\PhpBpmn\Enum\EventPosition;

/**
 * Writes Definitions as BPMN 2.0 XML that other tools open: the model, then
 * the diagram it was read with, when it has one. A model built in code has
 * none — symfony-bpmn-ds lays one out.
 */
class BpmnXmlWriter
{
    public const string EXPORTER = 'wexample/php-bpmn';

    private DOMDocument $document;

    public function write(Definitions $definitions): string
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;

        $root = $this->document->createElementNS(BpmnNamespace::MODEL, 'bpmn:definitions');
        $this->document->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:bpmndi', BpmnNamespace::BPMNDI);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', BpmnNamespace::DC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:di', BpmnNamespace::DI);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', BpmnNamespace::XSI);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:wex', BpmnNamespace::WEXAMPLE);
        $root->setAttribute('id', $definitions->getId());
        $root->setAttribute('targetNamespace', $definitions->getTargetNamespace());
        $root->setAttribute('exporter', $definitions->getExporter() ?? self::EXPORTER);

        if ($definitions->getExporterVersion() !== null) {
            $root->setAttribute('exporterVersion', $definitions->getExporterVersion());
        }

        foreach ($definitions->getReferences() as $reference) {
            $element = $this->element($root, $reference->getKind(), $reference);

            if ($reference->getCode() !== null) {
                $element->setAttribute($reference->getKind() === RootReference::ESCALATION ? 'escalationCode' : 'errorCode', $reference->getCode());
            }
        }

        foreach ($definitions->getCollaborations() as $collaboration) {
            $this->writeCollaboration($root, $collaboration);
        }

        foreach ($definitions->getProcesses() as $process) {
            $this->writeProcess($root, $process);
        }

        foreach ($definitions->getDiagrams() as $diagram) {
            $this->writeDiagram($root, $diagram);
        }

        return (string) $this->document->saveXML();
    }

    public function writeFile(Definitions $definitions, string $path): void
    {
        file_put_contents($path, $this->write($definitions));
    }

    private function writeProcess(DOMElement $root, Process $process): void
    {
        $element = $this->element($root, 'process', $process);
        $element->setAttribute('isExecutable', $process->isExecutable() ? 'true' : 'false');

        if ($process->getLanes()) {
            $laneSet = $this->bpmn($element, 'laneSet');
            $laneSet->setAttribute('id', $process->getLaneSetId() ?? $process->getId() . '_lanes');

            foreach ($process->getLanes() as $lane) {
                $this->writeLane($laneSet, $lane);
            }
        }

        $this->writeFlow($element, $process->getFlow());
    }

    private function writeLane(DOMElement $parent, Lane $lane): void
    {
        $element = $this->element($parent, 'lane', $lane);

        foreach ($lane->getNodeIds() as $nodeId) {
            $this->bpmn($element, 'flowNodeRef', $nodeId);
        }

        if ($lane->getChildren()) {
            $childSet = $this->bpmn($element, 'childLaneSet');
            $childSet->setAttribute('id', $lane->getId() . '_lanes');

            foreach ($lane->getChildren() as $child) {
                $this->writeLane($childSet, $child);
            }
        }
    }

    private function writeFlow(DOMElement $parent, FlowContainer $flow): void
    {
        foreach ($flow->getNodes() as $node) {
            $this->writeNode($parent, $node, $flow);
        }

        foreach ($flow->getFlows() as $sequenceFlow) {
            $element = $this->element($parent, 'sequenceFlow', $sequenceFlow);
            $element->setAttribute('sourceRef', $sequenceFlow->getSource());
            $element->setAttribute('targetRef', $sequenceFlow->getTarget());

            if ($sequenceFlow->getCondition() !== null) {
                $condition = $this->bpmn($element, 'conditionExpression', $sequenceFlow->getCondition());
                $condition->setAttributeNS(BpmnNamespace::XSI, 'xsi:type', 'bpmn:tFormalExpression');
            }
        }
    }

    private function writeNode(DOMElement $parent, AbstractFlowNode $node, FlowContainer $flow): void
    {
        $element = $this->element($parent, $node->getElementName(), $node);

        foreach ($flow->getIncoming($node->getId()) as $incoming) {
            $this->bpmn($element, 'incoming', $incoming->getId());
        }

        foreach ($flow->getOutgoing($node->getId()) as $outgoing) {
            $this->bpmn($element, 'outgoing', $outgoing->getId());
        }

        if ($node instanceof Task && $node->getMessageRef() !== null) {
            $element->setAttribute('messageRef', $node->getMessageRef());
        } elseif ($node instanceof Gateway && $node->getDefaultFlow() !== null) {
            $element->setAttribute('default', $node->getDefaultFlow());
        } elseif ($node instanceof CallActivity && $node->getCalledElement() !== null) {
            $element->setAttribute('calledElement', $node->getCalledElement());
        } elseif ($node instanceof SubProcess) {
            $this->writeFlow($element, $node->getFlow());
        } elseif ($node instanceof Event) {
            if ($node->getPosition() === EventPosition::BOUNDARY) {
                $element->setAttribute('attachedToRef', (string) $node->getAttachedTo());

                if (! $node->isCancelActivity()) {
                    $element->setAttribute('cancelActivity', 'false');
                }
            }

            foreach ($node->getDefinitions() as $definition) {
                $this->writeEventDefinition($element, $definition);
            }
        }
    }

    private function writeEventDefinition(DOMElement $parent, EventDefinition $definition): void
    {
        $element = $this->bpmn($parent, $definition->getType()->value);

        if ($definition->getId() !== null) {
            $element->setAttribute('id', $definition->getId());
        }

        $refAttribute = match ($definition->getType()) {
            EventDefinitionType::MESSAGE => 'messageRef',
            EventDefinitionType::SIGNAL => 'signalRef',
            EventDefinitionType::ERROR => 'errorRef',
            EventDefinitionType::ESCALATION => 'escalationRef',
            EventDefinitionType::LINK => 'name',
            default => null,
        };

        if ($refAttribute !== null && $definition->getRef() !== null) {
            $element->setAttribute($refAttribute, $definition->getRef());
        }

        if ($definition->getType() === EventDefinitionType::TIMER && $definition->getTimerKind() !== null) {
            $timer = $this->bpmn($element, $definition->getTimerKind()->value, (string) $definition->getExpression());
            $timer->setAttributeNS(BpmnNamespace::XSI, 'xsi:type', 'bpmn:tFormalExpression');
        }

        if ($definition->getType() === EventDefinitionType::CONDITIONAL && $definition->getExpression() !== null) {
            $condition = $this->bpmn($element, 'condition', $definition->getExpression());
            $condition->setAttributeNS(BpmnNamespace::XSI, 'xsi:type', 'bpmn:tFormalExpression');
        }
    }

    private function writeCollaboration(DOMElement $root, Collaboration $collaboration): void
    {
        $element = $this->element($root, 'collaboration', $collaboration);

        foreach ($collaboration->getParticipants() as $participant) {
            $child = $this->element($element, 'participant', $participant);

            if ($participant->getProcessRef() !== null) {
                $child->setAttribute('processRef', $participant->getProcessRef());
            }
        }

        foreach ($collaboration->getMessageFlows() as $flow) {
            $child = $this->element($element, 'messageFlow', $flow);
            $child->setAttribute('sourceRef', $flow->getSource());
            $child->setAttribute('targetRef', $flow->getTarget());

            if ($flow->getMessageRef() !== null) {
                $child->setAttribute('messageRef', $flow->getMessageRef());
            }
        }
    }

    private function writeDiagram(DOMElement $root, Diagram $diagram): void
    {
        $element = $root->appendChild($this->document->createElementNS(BpmnNamespace::BPMNDI, 'bpmndi:BPMNDiagram'));
        $element->setAttribute('id', $diagram->getId() ?? 'BPMNDiagram_1');
        $plane = $element->appendChild($this->document->createElementNS(BpmnNamespace::BPMNDI, 'bpmndi:BPMNPlane'));
        $plane->setAttribute('id', ($diagram->getId() ?? 'BPMNDiagram_1') . '_plane');

        if ($diagram->getPlaneElement() !== null) {
            $plane->setAttribute('bpmnElement', $diagram->getPlaneElement());
        }

        foreach ($diagram->getShapes() as $elementId => $bounds) {
            $shape = $plane->appendChild($this->document->createElementNS(BpmnNamespace::BPMNDI, 'bpmndi:BPMNShape'));
            $shape->setAttribute('id', $elementId . '_di');
            $shape->setAttribute('bpmnElement', $elementId);
            [$horizontal, $expanded] = $diagram->getShapeFlags($elementId);

            if ($horizontal !== null) {
                $shape->setAttribute('isHorizontal', $horizontal ? 'true' : 'false');
            }

            if ($expanded !== null) {
                $shape->setAttribute('isExpanded', $expanded ? 'true' : 'false');
            }

            $boundsElement = $shape->appendChild($this->document->createElementNS(BpmnNamespace::DC, 'dc:Bounds'));
            $boundsElement->setAttribute('x', (string) $bounds->x);
            $boundsElement->setAttribute('y', (string) $bounds->y);
            $boundsElement->setAttribute('width', (string) $bounds->width);
            $boundsElement->setAttribute('height', (string) $bounds->height);
        }

        foreach ($diagram->getEdges() as $elementId => $points) {
            $edge = $plane->appendChild($this->document->createElementNS(BpmnNamespace::BPMNDI, 'bpmndi:BPMNEdge'));
            $edge->setAttribute('id', $elementId . '_di');
            $edge->setAttribute('bpmnElement', $elementId);

            foreach ($points as [$x, $y]) {
                $point = $edge->appendChild($this->document->createElementNS(BpmnNamespace::DI, 'di:waypoint'));
                $point->setAttribute('x', (string) $x);
                $point->setAttribute('y', (string) $y);
            }
        }
    }

    /**
     * An element of the model, with its id, its name, its documentation and
     * our properties.
     */
    private function element(DOMElement $parent, string $localName, AbstractElement $model): DOMElement
    {
        $element = $this->bpmn($parent, $localName);
        $element->setAttribute('id', $model->getId());

        if ($model->getName() !== null) {
            $element->setAttribute('name', $model->getName());
        }

        if ($model->getDocumentation() !== null) {
            $this->bpmn($element, 'documentation', $model->getDocumentation());
        }

        if ($model->getProperties()) {
            $extensions = $this->bpmn($element, 'extensionElements');

            foreach ($model->getProperties() as $name => $value) {
                $property = $extensions->appendChild($this->document->createElementNS(BpmnNamespace::WEXAMPLE, 'wex:property'));
                $property->setAttribute('name', $name);
                $property->setAttribute('value', $value);
            }
        }

        return $element;
    }

    private function bpmn(DOMElement $parent, string $localName, ?string $text = null): DOMElement
    {
        $element = $this->document->createElementNS(BpmnNamespace::MODEL, 'bpmn:' . $localName);

        if ($text !== null) {
            $element->appendChild($this->document->createTextNode($text));
        }

        $parent->appendChild($element);

        return $element;
    }
}
