<?php

declare (strict_types=1);
namespace Sentry\Integration;

use WPSentry\ScopedVendor\Psr\Log\LoggerInterface;
use WPSentry\ScopedVendor\Psr\Log\NullLogger;
use Sentry\Client;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Util\Http;
final class OTLPIntegration implements \Sentry\Integration\OptionAwareIntegrationInterface
{
    /**
     * @var bool
     */
    private $setupOtlpTracesExporter;
    /**
     * @var string|null
     */
    private $collectorUrl;
    /**
     * @var Options|null
     */
    private $options;
    public function __construct(bool $setupOtlpTracesExporter = \true, ?string $collectorUrl = null)
    {
        $this->setupOtlpTracesExporter = $setupOtlpTracesExporter;
        $this->collectorUrl = $collectorUrl;
    }
    public function setOptions(\Sentry\Options $options) : void
    {
        $this->options = $options;
    }
    public function setupOnce() : void
    {
        $options = $this->options;
        if ($options === null) {
            $this->logDebug('Skipping OTLPIntegration setup because client options were not provided.');
            return;
        }
        if ($options->isTracingEnabled()) {
            $this->logDebug('Skipping OTLPIntegration because Sentry tracing is enabled. Disable "traces_sample_rate", "traces_sampler", and "enable_tracing" before using OTLPIntegration.');
            return;
        }
        \Sentry\State\Scope::registerExternalPropagationContext(static function () : ?array {
            $currentHub = \Sentry\SentrySdk::getCurrentHub();
            $integration = $currentHub->getIntegration(self::class);
            if (!$integration instanceof self) {
                return null;
            }
            return $integration->getCurrentOpenTelemetryPropagationContext();
        });
        if ($this->setupOtlpTracesExporter) {
            $this->configureOtlpTracesExporter($options);
        }
    }
    public function getCollectorUrl() : ?string
    {
        return $this->collectorUrl;
    }
    /**
     * @return array{trace_id: string, span_id: string}|null
     */
    private function getCurrentOpenTelemetryPropagationContext() : ?array
    {
        if (!\class_exists(\WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Span::class)) {
            return null;
        }
        $spanContext = \WPSentry\ScopedVendor\OpenTelemetry\API\Trace\Span::getCurrent()->getContext();
        if (!$spanContext->isValid()) {
            return null;
        }
        return ['trace_id' => $spanContext->getTraceId(), 'span_id' => $spanContext->getSpanId()];
    }
    private function configureOtlpTracesExporter(\Sentry\Options $options) : void
    {
        $endpoint = $this->collectorUrl;
        $headers = [];
        $dsn = $options->getDsn();
        if ($endpoint === null && $dsn !== null) {
            $endpoint = $dsn->getOtlpTracesEndpointUrl();
            $headers['X-Sentry-Auth'] = \Sentry\Util\Http::getSentryAuthHeader($dsn, \Sentry\Client::SDK_IDENTIFIER, \Sentry\Client::SDK_VERSION);
        }
        if ($endpoint === null) {
            $this->logDebug('Skipping automatic OTLP exporter setup because neither a DSN nor a collector URL is configured.');
            return;
        }
        if (!$this->shouldConfigureOtlpTracesExporter()) {
            return;
        }
        try {
            $transport = (new \WPSentry\ScopedVendor\OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())->create($endpoint, \WPSentry\ScopedVendor\OpenTelemetry\Contrib\Otlp\ContentTypes::PROTOBUF, $headers);
            $spanExporter = new \WPSentry\ScopedVendor\OpenTelemetry\Contrib\Otlp\SpanExporter($transport);
            $batchSpanProcessor = new \WPSentry\ScopedVendor\OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor($spanExporter, \WPSentry\ScopedVendor\OpenTelemetry\API\Common\Time\Clock::getDefault());
            (new \WPSentry\ScopedVendor\OpenTelemetry\SDK\SdkBuilder())->setTracerProvider(new \WPSentry\ScopedVendor\OpenTelemetry\SDK\Trace\TracerProvider($batchSpanProcessor))->buildAndRegisterGlobal();
        } catch (\Throwable $exception) {
            $this->logDebug(\sprintf('Skipping automatic OTLP exporter setup because it could not be configured: %s', $exception->getMessage()));
        }
    }
    private function shouldConfigureOtlpTracesExporter() : bool
    {
        if (\PHP_VERSION_ID < 80100) {
            $this->logDebug('Skipping automatic OTLP exporter setup because it requires PHP 8.1 or newer.');
            return \false;
        }
        foreach ([\WPSentry\ScopedVendor\OpenTelemetry\API\Globals::class, \WPSentry\ScopedVendor\OpenTelemetry\API\Common\Time\Clock::class, \WPSentry\ScopedVendor\OpenTelemetry\SDK\SdkBuilder::class, \WPSentry\ScopedVendor\OpenTelemetry\SDK\Trace\TracerProvider::class, \WPSentry\ScopedVendor\OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor::class, \WPSentry\ScopedVendor\OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory::class, \WPSentry\ScopedVendor\OpenTelemetry\Contrib\Otlp\SpanExporter::class] as $className) {
            if (!\class_exists($className)) {
                $this->logDebug('Skipping automatic OTLP exporter setup because the required OpenTelemetry SDK/exporter classes are not available.');
                return \false;
            }
        }
        try {
            if (!$this->isNoopTracerProvider(\WPSentry\ScopedVendor\OpenTelemetry\API\Globals::tracerProvider())) {
                $this->logDebug('Skipping automatic OTLP exporter setup because the existing OpenTelemetry tracer provider cannot be modified after construction.');
                return \false;
            }
        } catch (\Throwable $exception) {
            $this->logDebug(\sprintf('Skipping automatic OTLP exporter setup because the current OpenTelemetry tracer provider could not be inspected: %s', $exception->getMessage()));
            return \false;
        }
        return \true;
    }
    private function isNoopTracerProvider(?object $tracerProvider) : bool
    {
        return $tracerProvider === null || $tracerProvider instanceof \WPSentry\ScopedVendor\OpenTelemetry\API\Trace\NoopTracerProvider;
    }
    private function logDebug(string $message) : void
    {
        $this->getLogger()->debug($message);
    }
    private function getLogger() : \WPSentry\ScopedVendor\Psr\Log\LoggerInterface
    {
        if ($this->options !== null) {
            return $this->options->getLoggerOrNullLogger();
        }
        $currentHub = \Sentry\SentrySdk::getCurrentHub();
        $client = $currentHub->getClient();
        if ($client !== null) {
            return $client->getOptions()->getLoggerOrNullLogger();
        }
        return new \WPSentry\ScopedVendor\Psr\Log\NullLogger();
    }
}
