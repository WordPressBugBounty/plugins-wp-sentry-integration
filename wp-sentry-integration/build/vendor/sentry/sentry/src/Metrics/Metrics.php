<?php

declare (strict_types=1);
namespace Sentry\Metrics;

use Sentry\EventId;
use Sentry\Metrics\Types\CounterType;
use Sentry\Metrics\Types\DistributionType;
use Sentry\Metrics\Types\GaugeType;
use Sentry\Metrics\Types\SetType;
use Sentry\Tracing\SpanContext;
use function Sentry\trace;
class Metrics
{
    /**
     * @var self|null
     */
    private static $instance;
    /**
     * @var MetricsAggregator
     */
    private $aggregator;
    private function __construct()
    {
        $this->aggregator = new \Sentry\Metrics\MetricsAggregator();
    }
    public static function getInstance() : self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    /**
     * @param array<string, string> $tags
     */
    public function increment(string $key, float $value, ?\Sentry\Metrics\MetricsUnit $unit = null, array $tags = [], ?int $timestamp = null, int $stackLevel = 0) : void
    {
        $this->aggregator->add(\Sentry\Metrics\Types\CounterType::TYPE, $key, $value, $unit, $tags, $timestamp, $stackLevel);
    }
    /**
     * @param array<string, string> $tags
     */
    public function distribution(string $key, float $value, ?\Sentry\Metrics\MetricsUnit $unit = null, array $tags = [], ?int $timestamp = null, int $stackLevel = 0) : void
    {
        $this->aggregator->add(\Sentry\Metrics\Types\DistributionType::TYPE, $key, $value, $unit, $tags, $timestamp, $stackLevel);
    }
    /**
     * @param array<string, string> $tags
     */
    public function gauge(string $key, float $value, ?\Sentry\Metrics\MetricsUnit $unit = null, array $tags = [], ?int $timestamp = null, int $stackLevel = 0) : void
    {
        $this->aggregator->add(\Sentry\Metrics\Types\GaugeType::TYPE, $key, $value, $unit, $tags, $timestamp, $stackLevel);
    }
    /**
     * @param int|string            $value
     * @param array<string, string> $tags
     */
    public function set(string $key, $value, ?\Sentry\Metrics\MetricsUnit $unit = null, array $tags = [], ?int $timestamp = null, int $stackLevel = 0) : void
    {
        $this->aggregator->add(\Sentry\Metrics\Types\SetType::TYPE, $key, $value, $unit, $tags, $timestamp, $stackLevel);
    }
    /**
     * @template T
     *
     * @param callable(): T         $callback
     * @param array<string, string> $tags
     *
     * @return T
     */
    public function timing(string $key, callable $callback, array $tags = [], int $stackLevel = 0)
    {
        return \Sentry\trace(function () use($callback, $key, $tags, $stackLevel) {
            $startTimestamp = \microtime(\true);
            $result = $callback();
            /**
             * Emitting the metric here, will attach it to the
             * "metric.timing" span.
             */
            $this->aggregator->add(\Sentry\Metrics\Types\DistributionType::TYPE, $key, \microtime(\true) - $startTimestamp, \Sentry\Metrics\MetricsUnit::second(), $tags, (int) $startTimestamp, $stackLevel + 4);
            return $result;
        }, \Sentry\Tracing\SpanContext::make()->setOp('metric.timing')->setOrigin('auto.measure.metrics.timing')->setDescription($key));
    }
    public function flush() : ?\Sentry\EventId
    {
        return $this->aggregator->flush();
    }
}
