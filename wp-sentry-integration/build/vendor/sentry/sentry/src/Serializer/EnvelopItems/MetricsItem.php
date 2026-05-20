<?php

declare (strict_types=1);
namespace Sentry\Serializer\EnvelopItems;

use Sentry\Attributes\Attribute;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Metrics\Types\Metric;
use Sentry\Util\JSON;
/**
 * @internal
 */
class MetricsItem implements \Sentry\Serializer\EnvelopItems\EnvelopeItemInterface
{
    public static function toEnvelopeItem(\Sentry\Event $event) : string
    {
        $metrics = $event->getMetrics();
        $header = ['type' => (string) \Sentry\EventType::metrics(), 'item_count' => \count($metrics), 'content_type' => 'application/vnd.sentry.items.trace-metric+json'];
        return \sprintf("%s\n%s", \Sentry\Util\JSON::encode($header), \Sentry\Util\JSON::encode(['items' => \array_map(static function (\Sentry\Metrics\Types\Metric $metric) : array {
            return ['timestamp' => $metric->getTimestamp(), 'trace_id' => (string) $metric->getTraceId(), 'span_id' => (string) $metric->getSpanId(), 'name' => $metric->getName(), 'value' => $metric->getValue(), 'unit' => $metric->getUnit() ? (string) $metric->getUnit() : null, 'type' => $metric->getType(), 'attributes' => \array_map(static function (\Sentry\Attributes\Attribute $attribute) : array {
                return ['type' => $attribute->getType(), 'value' => $attribute->getValue()];
            }, $metric->getAttributes()->all())];
        }, $metrics)]));
    }
}
