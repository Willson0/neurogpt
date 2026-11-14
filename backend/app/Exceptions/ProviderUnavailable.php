<?php

namespace App\Exceptions;

use RuntimeException;

/** The model has no working provider yet: the job fails cleanly and the tokens go back. */
class ProviderUnavailable extends RuntimeException {}
