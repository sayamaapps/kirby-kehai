<?php

namespace Sayamaapps\Kehai;

use RuntimeException;

/**
 * A failed fetch. The message is safe to print: no key, no personal data, no control characters.
 *
 * @package   Kirby Kehai
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-kehai
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class SourceException extends RuntimeException
{
}
