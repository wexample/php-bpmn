<?php

namespace Wexample\PhpBpmn\Class\Diagram;

/**
 * Where a shape is drawn, and how large.
 */
final readonly class Bounds
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
    }
}
