<?php

namespace Wexample\PhpBpmn\Class\Xml;

/**
 * The XML namespaces of a BPMN 2.0 document, and ours.
 */
final class BpmnNamespace
{
    public const string MODEL = 'http://www.omg.org/spec/BPMN/20100524/MODEL';
    public const string BPMNDI = 'http://www.omg.org/spec/BPMN/20100524/DI';
    public const string DC = 'http://www.omg.org/spec/DD/20100524/DC';
    public const string DI = 'http://www.omg.org/spec/DD/20100524/DI';
    public const string XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /** The properties BPMN has no word for, in `extensionElements`. */
    public const string WEXAMPLE = 'https://wexample.com/schema/bpmn';
}
