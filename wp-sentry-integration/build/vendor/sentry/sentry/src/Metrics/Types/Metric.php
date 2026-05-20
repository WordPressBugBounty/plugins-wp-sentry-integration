<?php

declare (strict_types=1);
namespace Sentry\Metrics\Types;

use Sentry\Attributes\AttributeBag;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\TraceId;
use Sentry\Unit;
abstract class Metric
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var TraceId
     */
    private $traceId;
    /**
     * @var SpanId
     */
    private $spanId;
    /**
     * @var float
     */
    private $timestamp;
    /**
     * @var AttributeBag
     */
    private $attributes;
    /**
     * @var Unit|null
     */
    private $unit;
    /**
     * @param array<string, int|float|string|bool> $attributes
     */
    public function __construct(string $name, \Sentry\Tracing\TraceId $traceId, \Sentry\Tracing\SpanId $spanId, float $timestamp, array $attributes, ?\Sentry\Unit $unit)
    {
        $this->name = $name;
        $this->unit = $unit;
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->timestamp = $timestamp;
        $this->attributes = new \Sentry\Attributes\AttributeBag();
        foreach ($attributes as $key => $value) {
            $this->attributes->set($key, $value);
        }
    }
    /**
     * @param int|float $value
     */
    public abstract function setValue($value) : void;
    public abstract function getType() : string;
    /**
     * @return int|float
     */
    public abstract function getValue();
    public function getName() : string
    {
        return $this->name;
    }
    public function getUnit() : ?\Sentry\Unit
    {
        return $this->unit;
    }
    public function getTraceId() : \Sentry\Tracing\TraceId
    {
        return $this->traceId;
    }
    public function getSpanId() : \Sentry\Tracing\SpanId
    {
        return $this->spanId;
    }
    public function getAttributes() : \Sentry\Attributes\AttributeBag
    {
        return $this->attributes;
    }
    public function getTimestamp() : float
    {
        return $this->timestamp;
    }
}
