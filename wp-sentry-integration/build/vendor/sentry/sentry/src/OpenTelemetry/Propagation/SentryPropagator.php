<?php

declare (strict_types=1);
namespace Sentry\OpenTelemetry\Propagation;

use WPSentry\ScopedVendor\OpenTelemetry\API\Globals;
use WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Span;
use WPSentry\ScopedVendor\OpenTelemetry\API\Trace\SpanContext;
use WPSentry\ScopedVendor\OpenTelemetry\API\Trace\TraceFlags;
use WPSentry\ScopedVendor\OpenTelemetry\Context\Context;
use WPSentry\ScopedVendor\OpenTelemetry\Context\ContextInterface;
use WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
class SentryPropagator implements \WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\TextMapPropagatorInterface
{
    public const SENTRY_TRACE = 'sentry-trace';
    /**
     * @var self|null
     */
    private static $instance;
    public static function getInstance() : self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function fields() : array
    {
        return [self::SENTRY_TRACE];
    }
    /**
     * @param mixed $carrier
     */
    public function inject(&$carrier, ?\WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\PropagationSetterInterface $setter = null, ?\WPSentry\ScopedVendor\OpenTelemetry\Context\ContextInterface $context = null) : void
    {
        if ($setter === null) {
            $setter = \WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter::getInstance();
        }
        if ($context === null) {
            $context = \WPSentry\ScopedVendor\OpenTelemetry\Context\Context::getCurrent();
        }
        $spanContext = \WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Span::fromContext($context)->getContext();
        if (!$spanContext->isValid()) {
            return;
        }
        $sampled = $spanContext->isSampled() ? '1' : '0';
        $sentryTrace = \sprintf('%s-%s-%s', $spanContext->getTraceId(), $spanContext->getSpanId(), $sampled);
        $setter->set($carrier, self::SENTRY_TRACE, $sentryTrace);
    }
    /**
     * @param mixed $carrier
     */
    public function extract($carrier, ?\WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\PropagationGetterInterface $getter = null, ?\WPSentry\ScopedVendor\OpenTelemetry\Context\ContextInterface $context = null) : \WPSentry\ScopedVendor\OpenTelemetry\Context\ContextInterface
    {
        if ($getter === null) {
            $getter = \WPSentry\ScopedVendor\OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter::getInstance();
        }
        if ($context === null) {
            $context = \WPSentry\ScopedVendor\OpenTelemetry\Context\Context::getCurrent();
        }
        // Traceparent header has higher precedence over sentry-trace header if traceparent propagator is enabled.
        if (!empty($getter->get($carrier, \WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::TRACEPARENT)) && $this->isTraceparentPropagatorEnabled()) {
            return $context;
        }
        $sentryTrace = $getter->get($carrier, self::SENTRY_TRACE);
        if ($sentryTrace === null) {
            return $context;
        }
        // Format: sentry-trace = {trace-id}-{span-id}-{sampled flag (optional)}.
        $parts = \explode('-', $sentryTrace);
        // If the header does not have at least 2 parts, it is invalid.
        if (\count($parts) < 2) {
            return $context;
        }
        [$traceId, $spanId] = $parts;
        $traceFlags = isset($parts[2]) && $parts[2] === '1' ? \WPSentry\ScopedVendor\OpenTelemetry\API\Trace\TraceFlags::SAMPLED : \WPSentry\ScopedVendor\OpenTelemetry\API\Trace\TraceFlags::DEFAULT;
        $spanContext = \WPSentry\ScopedVendor\OpenTelemetry\API\Trace\SpanContext::createFromRemoteParent($traceId, $spanId, $traceFlags);
        if (!$spanContext->isValid()) {
            return $context;
        }
        return $context->withContextValue(\WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Span::wrap($spanContext));
    }
    private function isTraceparentPropagatorEnabled() : bool
    {
        return \in_array(\WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::TRACEPARENT, \WPSentry\ScopedVendor\OpenTelemetry\API\Globals::propagator()->fields());
    }
}
