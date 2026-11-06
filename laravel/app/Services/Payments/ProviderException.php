<?php

namespace App\Services\Payments;

use RuntimeException;

/** A payment provider (M-PESA, Paystack) refused a request or couldn't be reached. */
class ProviderException extends RuntimeException {}
