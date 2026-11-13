<?php

namespace App\Exceptions;

use RuntimeException;

/** The provider refused the request because of its content rules: the tokens go back, the user is told why. */
class ContentFiltered extends RuntimeException {}
