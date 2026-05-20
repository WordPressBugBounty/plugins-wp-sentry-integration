<?php

declare (strict_types=1);
namespace Sentry\Metrics;

use Sentry\EventId;
use Sentry\Metrics\Types\CounterMetric;
use Sentry\Metrics\Types\DistributionMetric;
use Sentry\Metrics\Types\GaugeMetric;
use Sentry\SentrySdk;
use Sentry\Unit;
class TraceMetrics
{
    /**
     * @var self|null
     */
    private static $instance;
    public function __construct()
    {
    }
    public static function getInstance() : self
    {
        if (self::$instance === null) {
            self::$instance = new \Sentry\Metrics\TraceMetrics();
        }
        return self::$instance;
    }
    /**
     * @param int|float                            $value
     * @param array<string, int|float|string|bool> $attributes
     */
    public function count(string $name, $value, array $attributes = [], ?\Sentry\Unit $unit = null) : void
    {
        $this->aggregator()->add(\Sentry\Metrics\Types\CounterMetric::TYPE, $name, $value, $attributes, $unit);
    }
    /**
     * @param int|float                            $value
     * @param array<string, int|float|string|bool> $attributes
     */
    public function distribution(string $name, $value, array $attributes = [], ?\Sentry\Unit $unit = null) : void
    {
        $this->aggregator()->add(\Sentry\Metrics\Types\DistributionMetric::TYPE, $name, $value, $attributes, $unit);
    }
    /**
     * @param int|float                            $value
     * @param array<string, int|float|string|bool> $attributes
     */
    public function gauge(string $name, $value, array $attributes = [], ?\Sentry\Unit $unit = null) : void
    {
        $this->aggregator()->add(\Sentry\Metrics\Types\GaugeMetric::TYPE, $name, $value, $attributes, $unit);
    }
    public function flush() : ?\Sentry\EventId
    {
        return $this->aggregator()->flush();
    }
    private function aggregator() : \Sentry\Metrics\MetricsAggregator
    {
        return \Sentry\SentrySdk::getCurrentRuntimeContext()->getMetricsAggregator();
    }
}
