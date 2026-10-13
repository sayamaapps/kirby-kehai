<?php

namespace Kehai;

/** A failed fetch. The message is safe to print: no key, no personal data, no control characters. */
final class SourceException extends \RuntimeException
{
}
