<?php

declare (strict_types=1);
namespace Sentry\Metrics;

use Sentry\Event;
use Sentry\EventId;
use Sentry\Metrics\Types\AbstractType;
use Sentry\Metrics\Types\CounterType;
use Sentry\Metrics\Types\DistributionType;
use Sentry\Metrics\Types\GaugeType;
use Sentry\Metrics\Types\SetType;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\TransactionSource;
/**
 * @internal
 */
final class MetricsAggregator
{
    /**
     * @var int
     */
    private const ROLLUP_IN_SECONDS = 10;
    /**
     * @var array<string, AbstractType>
     */
    private $buckets = [];
    private const METRIC_TYPES = [\Sentry\Metrics\Types\CounterType::TYPE => \Sentry\Metrics\Types\CounterType::class, \Sentry\Metrics\Types\DistributionType::TYPE => \Sentry\Metrics\Types\DistributionType::class, \Sentry\Metrics\Types\GaugeType::TYPE => \Sentry\Metrics\Types\GaugeType::class, \Sentry\Metrics\Types\SetType::TYPE => \Sentry\Metrics\Types\SetType::class];
    /**
     * @param array<string, string> $tags
     * @param int|float|string      $value
     */
    public function add(string $type, string $key, $value, ?\Sentry\Metrics\MetricsUnit $unit, array $tags, ?int $timestamp, int $stackLevel) : void
    {
        if ($timestamp === null) {
            $timestamp = \time();
        }
        if ($unit === null) {
            $unit = \Sentry\Metrics\MetricsUnit::none();
        }
        $tags = $this->serializeTags($tags);
        $bucketTimestamp = \floor($timestamp / self::ROLLUP_IN_SECONDS);
        $bucketKey = \md5($type . $key . $unit . \serialize($tags) . $bucketTimestamp);
        if (\array_key_exists($bucketKey, $this->buckets)) {
            $metric = $this->buckets[$bucketKey];
            $metric->add($value);
        } else {
            $metricTypeClass = self::METRIC_TYPES[$type];
            /** @var AbstractType $metric */
            /** @phpstan-ignore-next-line SetType accepts int|float|string, others only int|float */
            $metric = new $metricTypeClass($key, $value, $unit, $tags, $timestamp);
            $this->buckets[$bucketKey] = $metric;
        }
        $hub = \Sentry\SentrySdk::getCurrentHub();
        $client = $hub->getClient();
        if ($client !== null) {
            $options = $client->getOptions();
            if ($options->shouldAttachMetricCodeLocations() && !$metric->hasCodeLocation()) {
                $metric->addCodeLocation($stackLevel);
            }
        }
        $span = $hub->getSpan();
        if ($span !== null) {
            $span->setMetricsSummary($type, $key, $value, $unit, $tags);
        }
    }
    public function flush() : ?\Sentry\EventId
    {
        if ($this->buckets === []) {
            return null;
        }
        $hub = \Sentry\SentrySdk::getCurrentHub();
        $event = \Sentry\Event::createMetrics()->setMetrics($this->buckets);
        $this->buckets = [];
        return $hub->captureEvent($event);
    }
    /**
     * @param array<string, string> $tags
     *
     * @return array<string, string>
     */
    private function serializeTags(array $tags) : array
    {
        $hub = \Sentry\SentrySdk::getCurrentHub();
        $client = $hub->getClient();
        if ($client !== null) {
            $options = $client->getOptions();
            $defaultTags = ['environment' => $options->getEnvironment() ?? \Sentry\Event::DEFAULT_ENVIRONMENT];
            $release = $options->getRelease();
            if ($release !== null) {
                $defaultTags['release'] = $release;
            }
            $hub->configureScope(function (\Sentry\State\Scope $scope) use(&$defaultTags) {
                $transaction = $scope->getTransaction();
                if ($transaction !== null && $transaction->getMetadata()->getSource() !== \Sentry\Tracing\TransactionSource::url()) {
                    $defaultTags['transaction'] = $transaction->getName();
                }
            });
            $tags = \array_merge($defaultTags, $tags);
        }
        // It's very important to sort the tags in order to obtain the same bucket key.
        \ksort($tags);
        return $tags;
    }
}
