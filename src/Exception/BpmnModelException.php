<?php

namespace Wexample\PhpBpmn\Exception;

use RuntimeException;

/**
 * A model that cannot be what it is asked to be: an id used twice, a flow to
 * nothing.
 */
class BpmnModelException extends RuntimeException
{
}
