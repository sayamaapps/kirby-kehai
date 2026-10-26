<?php

namespace Sayamaapps\Kehai;

use RuntimeException;

/** A failed fetch. The message is safe to print: no key, no personal data, no control characters. */
final class SourceException extends RuntimeException
{
}
