<?php

declare (strict_types=1);
namespace Sentry\Serializer\EnvelopItems;

use Sentry\ClientReport\DiscardedEvent;
use Sentry\Event;
use Sentry\Util\JSON;
class ClientReportItem implements \Sentry\Serializer\EnvelopItems\EnvelopeItemInterface
{
    public static function toEnvelopeItem(\Sentry\Event $event) : ?string
    {
        $reports = $event->getClientReports();
        $headers = ['type' => 'client_report'];
        $body = ['timestamp' => $event->getTimestamp(), 'discarded_events' => \array_map(static function (\Sentry\ClientReport\DiscardedEvent $report) {
            return ['category' => $report->getCategory(), 'reason' => $report->getReason(), 'quantity' => $report->getQuantity()];
        }, $reports)];
        return \sprintf("%s\n%s", \Sentry\Util\JSON::encode($headers), \Sentry\Util\JSON::encode($body));
    }
}
