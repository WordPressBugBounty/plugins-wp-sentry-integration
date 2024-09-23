<?php

declare (strict_types=1);
namespace Sentry\Logger;

use WPSentry\ScopedVendor\Psr\Log\AbstractLogger;
class DebugStdOutLogger extends \WPSentry\ScopedVendor\Psr\Log\AbstractLogger
{
    /**
     * @param mixed   $level
     * @param mixed[] $context
     */
    public function log($level, $message, array $context = []) : void
    {
        \file_put_contents('php://stdout', \sprintf("sentry/sentry: [%s] %s\n", $level, (string) $message));
    }
}
