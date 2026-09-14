<?php

namespace ByJG\Gluo\Laravel\Exception;

use RuntimeException;

/**
 * Thrown when `gluo.openapi.spec` does not point at a readable file.
 */
class SpecificationNotFoundException extends RuntimeException
{
}
