<?php

namespace Wexample\PhpBpmn\Enum;

enum IssueSeverity: string
{
    /** The model is wrong: it cannot be run, nor trusted. */
    case ERROR = 'error';

    /** The model is BPMN, but not what this library runs, or likely not what was meant. */
    case WARNING = 'warning';
}
