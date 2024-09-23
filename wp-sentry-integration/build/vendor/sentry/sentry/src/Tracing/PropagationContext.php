<?php

declare (strict_types=1);
namespace Sentry\Tracing;

use Sentry\SentrySdk;
use Sentry\State\Scope;
final class PropagationContext
{
    private const SENTRY_TRACEPARENT_HEADER_REGEX = '/^[ \\t]*(?<trace_id>[0-9a-f]{32})?-?(?<span_id>[0-9a-f]{16})?-?(?<sampled>[01])?[ \\t]*$/i';
    private const W3C_TRACEPARENT_HEADER_REGEX = '/^[ \\t]*(?<version>[0]{2})?-?(?<trace_id>[0-9a-f]{32})?-?(?<span_id>[0-9a-f]{16})?-?(?<sampled>[01]{2})?[ \\t]*$/i';
    /**
     * @var TraceId The trace id
     */
    private $traceId;
    /**
     * @var SpanId The span id
     */
    private $spanId;
    /**
     * @var SpanId|null The parent span id
     */
    private $parentSpanId;
    /**
     * @var DynamicSamplingContext|null The dynamic sampling context
     */
    private $dynamicSamplingContext;
    private function __construct()
    {
    }
    public static function fromDefaults() : self
    {
        $context = new self();
        $context->traceId = \Sentry\Tracing\TraceId::generate();
        $context->spanId = \Sentry\Tracing\SpanId::generate();
        $context->parentSpanId = null;
        $context->dynamicSamplingContext = null;
        return $context;
    }
    public static function fromHeaders(string $sentryTraceHeader, string $baggageHeader) : self
    {
        return self::parseTraceparentAndBaggage($sentryTraceHeader, $baggageHeader);
    }
    public static function fromEnvironment(string $sentryTrace, string $baggage) : self
    {
        return self::parseTraceparentAndBaggage($sentryTrace, $baggage);
    }
    /**
     * Returns a string that can be used for the `sentry-trace` header & meta tag.
     */
    public function toTraceparent() : string
    {
        return \sprintf('%s-%s', (string) $this->traceId, (string) $this->spanId);
    }
    /**
     * Returns a string that can be used for the W3C `traceparent` header & meta tag.
     */
    public function toW3CTraceparent() : string
    {
        return \sprintf('00-%s-%s-00', (string) $this->traceId, (string) $this->spanId);
    }
    /**
     * Returns a string that can be used for the `baggage` header & meta tag.
     */
    public function toBaggage() : string
    {
        if ($this->dynamicSamplingContext === null) {
            $hub = \Sentry\SentrySdk::getCurrentHub();
            $client = $hub->getClient();
            if ($client !== null) {
                $options = $client->getOptions();
                if ($options !== null) {
                    $hub->configureScope(function (\Sentry\State\Scope $scope) use($options) {
                        $this->dynamicSamplingContext = \Sentry\Tracing\DynamicSamplingContext::fromOptions($options, $scope);
                    });
                }
            }
        }
        return (string) $this->dynamicSamplingContext;
    }
    /**
     * @return array<string, mixed>
     */
    public function getTraceContext() : array
    {
        $result = ['trace_id' => (string) $this->traceId, 'span_id' => (string) $this->spanId];
        if ($this->parentSpanId !== null) {
            $result['parent_span_id'] = (string) $this->parentSpanId;
        }
        return $result;
    }
    public function getTraceId() : \Sentry\Tracing\TraceId
    {
        return $this->traceId;
    }
    public function setTraceId(\Sentry\Tracing\TraceId $traceId) : void
    {
        $this->traceId = $traceId;
    }
    public function getParentSpanId() : ?\Sentry\Tracing\SpanId
    {
        return $this->parentSpanId;
    }
    public function setParentSpanId(?\Sentry\Tracing\SpanId $parentSpanId) : void
    {
        $this->parentSpanId = $parentSpanId;
    }
    public function getSpanId() : \Sentry\Tracing\SpanId
    {
        return $this->spanId;
    }
    public function setSpanId(\Sentry\Tracing\SpanId $spanId) : self
    {
        $this->spanId = $spanId;
        return $this;
    }
    public function getDynamicSamplingContext() : ?\Sentry\Tracing\DynamicSamplingContext
    {
        return $this->dynamicSamplingContext;
    }
    public function setDynamicSamplingContext(\Sentry\Tracing\DynamicSamplingContext $dynamicSamplingContext) : self
    {
        $this->dynamicSamplingContext = $dynamicSamplingContext;
        return $this;
    }
    private static function parseTraceparentAndBaggage(string $traceparent, string $baggage) : self
    {
        $context = self::fromDefaults();
        $hasSentryTrace = \false;
        if (\preg_match(self::SENTRY_TRACEPARENT_HEADER_REGEX, $traceparent, $matches)) {
            if (!empty($matches['trace_id'])) {
                $context->traceId = new \Sentry\Tracing\TraceId($matches['trace_id']);
                $hasSentryTrace = \true;
            }
            if (!empty($matches['span_id'])) {
                $context->parentSpanId = new \Sentry\Tracing\SpanId($matches['span_id']);
                $hasSentryTrace = \true;
            }
        } elseif (\preg_match(self::W3C_TRACEPARENT_HEADER_REGEX, $traceparent, $matches)) {
            if (!empty($matches['trace_id'])) {
                $context->traceId = new \Sentry\Tracing\TraceId($matches['trace_id']);
                $hasSentryTrace = \true;
            }
            if (!empty($matches['span_id'])) {
                $context->parentSpanId = new \Sentry\Tracing\SpanId($matches['span_id']);
                $hasSentryTrace = \true;
            }
        }
        $samplingContext = \Sentry\Tracing\DynamicSamplingContext::fromHeader($baggage);
        if ($hasSentryTrace && !$samplingContext->hasEntries()) {
            // The request comes from an old SDK which does not support Dynamic Sampling.
            // Propagate the Dynamic Sampling Context as is, but frozen, even without sentry-* entries.
            $samplingContext->freeze();
            $context->dynamicSamplingContext = $samplingContext;
        }
        if ($hasSentryTrace && $samplingContext->hasEntries()) {
            // The baggage header contains Dynamic Sampling Context data from an upstream SDK.
            // Propagate this Dynamic Sampling Context.
            $context->dynamicSamplingContext = $samplingContext;
        }
        return $context;
    }
}
