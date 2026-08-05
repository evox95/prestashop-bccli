<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */

namespace Bccli\Thumbnails;

/**
 * Thrown when this PrestaShop build does not expose the internals the bridge
 * needs. Always caught by the command and reported as a message plus exit 1 -
 * never allowed to surface as a PHP fatal error.
 */
class UnsupportedPlatformException extends \RuntimeException
{
}
