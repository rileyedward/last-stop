<?php

namespace App\Game\Engine;

use RuntimeException;

/**
 * An action the rules don't allow right now. The message is shown to the
 * player as is.
 */
final class RuleViolation extends RuntimeException {}
