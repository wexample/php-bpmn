<?php

namespace Wexample\PhpBpmn\Exception;

use RuntimeException;

/**
 * A document that is not BPMN 2.0 XML, or not well formed.
 */
class BpmnParseException extends RuntimeException
{
}
