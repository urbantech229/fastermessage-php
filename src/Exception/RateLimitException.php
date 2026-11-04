<?php

declare(strict_types=1);

namespace FasterMessage\Exception;

/** 429, voir retryAfter. */
class RateLimitException extends ApiException
{
}
