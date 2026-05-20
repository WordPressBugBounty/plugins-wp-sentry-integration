<?php

declare (strict_types=1);
namespace Sentry\Metrics;

use Sentry\Client;
use Sentry\Event;
use Sentry\EventId;
use Sentry\Metrics\Types\CounterMetric;
use Sentry\Metrics\Types\DistributionMetric;
use Sentry\Metrics\Types\GaugeMetric;
use Sentry\Metrics\Types\Metric;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\TraceId;
use Sentry\Unit;
use Sentry\Util\TelemetryStorage;
/**
 * @internal
 */
final class MetricsAggregator
{
    /**
     * @var int
     */
    public const METRICS_BUFFER_SIZE = 1000;
    private const METRIC_TYPES = [\Sentry\Metrics\Types\CounterMetric::TYPE => \Sentry\Metrics\Types\CounterMetric::class, \Sentry\Metrics\Types\DistributionMetric::TYPE => \Sentry\Metrics\Types\DistributionMetric::class, \Sentry\Metrics\Types\GaugeMetric::TYPE => \Sentry\Metrics\Types\GaugeMetric::class];
    /**
     * @var TelemetryStorage<Metric>|null
     */
    private $metrics;
    /**
     * @param int|float                            $value
     * @param array<string, int|float|string|bool> $attributes
     */
    public function add(string $type, string $name, $value, array $attributes, ?\Sentry\Unit $unit) : void
    {
        $hub = \Sentry\SentrySdk::getCurrentHub();
        $client = $hub->getClient();
        $metricFlushThreshold = null;
        if (!\is_int($value) && !\is_float($value)) {
            if ($client !== null) {
                $client->getOptions()->getLoggerOrNullLogger()->debug('Metrics value is neither int nor float. Metric will be discarded');
            }
            return;
        }
        if ($client !== null) {
            $options = $client->getOptions();
            $metricFlushThreshold = $options->getMetricFlushThreshold();
            if ($options->getEnableMetrics() === \false) {
                return;
            }
            $defaultAttributes = ['sentry.environment' => $options->getEnvironment() ?? \Sentry\Event::DEFAULT_ENVIRONMENT, 'server.address' => $options->getServerName()];
            if ($client instanceof \Sentry\Client) {
                $defaultAttributes['sentry.sdk.name'] = $client->getSdkIdentifier();
                $defaultAttributes['sentry.sdk.version'] = $client->getSdkVersion();
            }
            $hub->configureScope(static function (\Sentry\State\Scope $scope) use(&$defaultAttributes) {
                $user = $scope->getUser();
                if ($user !== null) {
                    if ($user->getId() !== null) {
                        $defaultAttributes['user.id'] = $user->getId();
                    }
                    if ($user->getEmail() !== null) {
                        $defaultAttributes['user.email'] = $user->getEmail();
                    }
                    if ($user->getUsername() !== null) {
                        $defaultAttributes['user.name'] = $user->getUsername();
                    }
                }
            });
            $release = $options->getRelease();
            if ($release !== null) {
                $defaultAttributes['sentry.release'] = $release;
            }
            $attributes += $defaultAttributes;
        }
        $traceContext = $this->getTraceContext($hub);
        $traceId = new \Sentry\Tracing\TraceId($traceContext['trace_id']);
        $spanId = new \Sentry\Tracing\SpanId($traceContext['span_id']);
        $metricTypeClass = self::METRIC_TYPES[$type];
        /** @var Metric $metric */
        $metric = new $metricTypeClass($name, $value, $traceId, $spanId, $attributes, \microtime(\true), $unit);
        if ($client !== null) {
            $beforeSendMetric = $client->getOptions()->getBeforeSendMetricCallback();
            $metric = $beforeSendMetric($metric);
            if ($metric === null) {
                return;
            }
        }
        $metrics = $this->getStorage($metricFlushThreshold);
        $metrics->push($metric);
        if ($metricFlushThreshold !== null && \count($metrics) >= $metricFlushThreshold) {
            $this->flush($hub);
        }
    }
    public function flush(?\Sentry\State\HubInterface $hub = null) : ?\Sentry\EventId
    {
        if ($this->metrics === null || $this->metrics->isEmpty()) {
            return null;
        }
        $hub = $hub ?? \Sentry\SentrySdk::getCurrentHub();
        $event = \Sentry\Event::createMetrics()->setMetrics($this->metrics->drain());
        return $hub->captureEvent($event);
    }
    /**
     * @return array{trace_id: string, span_id: string}
     */
    private function getTraceContext(\Sentry\State\HubInterface $hub) : array
    {
        $traceContext = null;
        $hub->configureScope(static function (\Sentry\State\Scope $scope) use(&$traceContext) : void {
            $traceContext = $scope->getTraceContext();
        });
        /** @var array{trace_id: string, span_id: string} $traceContext */
        return $traceContext;
    }
    /**
     * @return TelemetryStorage<Metric>
     */
    private function getStorage(?int $metricFlushThreshold = null) : \Sentry\Util\TelemetryStorage
    {
        if ($this->metrics === null) {
            /** @var TelemetryStorage<Metric> $metrics */
            $metrics = $metricFlushThreshold !== null ? \Sentry\Util\TelemetryStorage::unbounded() : \Sentry\Util\TelemetryStorage::bounded(self::METRICS_BUFFER_SIZE);
            $this->metrics = $metrics;
        }
        return $this->metrics;
    }
}
