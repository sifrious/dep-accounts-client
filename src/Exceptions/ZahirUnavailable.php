<?php

namespace Sifrious\AccountsClient\Exceptions;

use RuntimeException;

/**
 * Zahir could not be reached, or answered in a way that means "ask again later"
 * — a connection failure, a timeout, a 5xx, or a throttle response.
 *
 * This is deliberately distinct from a denial. Collapsing an outage into "no
 * access" would lock every entitled person out of a product during an incident
 * and, worse, would look identical to a revocation in the logs.
 */
final class ZahirUnavailable extends RuntimeException {}
