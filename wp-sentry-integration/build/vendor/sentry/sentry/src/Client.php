<?php

declare (strict_types=1);
namespace Sentry;

use WPSentry\ScopedVendor\Psr\Log\LoggerInterface;
use WPSentry\ScopedVendor\Psr\Log\NullLogger;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\IntegrationRegistry;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\RepresentationSerializerInterface;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\TransportInterface;
/**
 * Default implementation of the {@see ClientInterface} interface.
 */
class Client implements \Sentry\ClientInterface
{
    /**
     * The version of the protocol to communicate with the Sentry server.
     */
    public const PROTOCOL_VERSION = '7';
    /**
     * The identifier of the SDK.
     */
    public const SDK_IDENTIFIER = 'sentry.php';
    /**
     * The version of the SDK.
     */
    public const SDK_VERSION = '4.9.0';
    /**
     * @var Options The client options
     */
    private $options;
    /**
     * @var TransportInterface The transport
     */
    private $transport;
    /**
     * @var LoggerInterface The PSR-3 logger
     */
    private $logger;
    /**
     * @var array<string, IntegrationInterface> The stack of integrations
     *
     * @psalm-var array<class-string<IntegrationInterface>, IntegrationInterface>
     */
    private $integrations;
    /**
     * @var StacktraceBuilder
     */
    private $stacktraceBuilder;
    /**
     * @var string The Sentry SDK identifier
     */
    private $sdkIdentifier;
    /**
     * @var string The SDK version of the Client
     */
    private $sdkVersion;
    /**
     * Constructor.
     *
     * @param Options                                $options                  The client configuration
     * @param TransportInterface                     $transport                The transport
     * @param string|null                            $sdkIdentifier            The Sentry SDK identifier
     * @param string|null                            $sdkVersion               The Sentry SDK version
     * @param RepresentationSerializerInterface|null $representationSerializer The serializer for function arguments
     * @param LoggerInterface|null                   $logger                   The PSR-3 logger
     */
    public function __construct(\Sentry\Options $options, \Sentry\Transport\TransportInterface $transport, ?string $sdkIdentifier = null, ?string $sdkVersion = null, ?\Sentry\Serializer\RepresentationSerializerInterface $representationSerializer = null, ?\WPSentry\ScopedVendor\Psr\Log\LoggerInterface $logger = null)
    {
        $this->options = $options;
        $this->transport = $transport;
        $this->sdkIdentifier = $sdkIdentifier ?? self::SDK_IDENTIFIER;
        $this->sdkVersion = $sdkVersion ?? self::SDK_VERSION;
        $this->stacktraceBuilder = new \Sentry\StacktraceBuilder($options, $representationSerializer ?? new \Sentry\Serializer\RepresentationSerializer($this->options));
        $this->logger = $logger ?? new \WPSentry\ScopedVendor\Psr\Log\NullLogger();
        $this->integrations = \Sentry\Integration\IntegrationRegistry::getInstance()->setupIntegrations($options, $this->logger);
    }
    /**
     * {@inheritdoc}
     */
    public function getOptions() : \Sentry\Options
    {
        return $this->options;
    }
    /**
     * {@inheritdoc}
     */
    public function getCspReportUrl() : ?string
    {
        $dsn = $this->options->getDsn();
        if ($dsn === null) {
            return null;
        }
        $endpoint = $dsn->getCspReportEndpointUrl();
        $query = \array_filter(['sentry_release' => $this->options->getRelease(), 'sentry_environment' => $this->options->getEnvironment()]);
        if (!empty($query)) {
            $endpoint .= '&' . \http_build_query($query, '', '&');
        }
        return $endpoint;
    }
    /**
     * {@inheritdoc}
     */
    public function captureMessage(string $message, ?\Sentry\Severity $level = null, ?\Sentry\State\Scope $scope = null, ?\Sentry\EventHint $hint = null) : ?\Sentry\EventId
    {
        $event = \Sentry\Event::createEvent();
        $event->setMessage($message);
        $event->setLevel($level);
        return $this->captureEvent($event, $hint, $scope);
    }
    /**
     * {@inheritdoc}
     */
    public function captureException(\Throwable $exception, ?\Sentry\State\Scope $scope = null, ?\Sentry\EventHint $hint = null) : ?\Sentry\EventId
    {
        $className = \get_class($exception);
        if ($this->isIgnoredException($className)) {
            $this->logger->info('The exception will be discarded because it matches an entry in "ignore_exceptions".', ['className' => $className]);
            return null;
            // short circuit to avoid unnecessary processing
        }
        $hint = $hint ?? new \Sentry\EventHint();
        if ($hint->exception === null) {
            $hint->exception = $exception;
        }
        return $this->captureEvent(\Sentry\Event::createEvent(), $hint, $scope);
    }
    /**
     * {@inheritdoc}
     */
    public function captureEvent(\Sentry\Event $event, ?\Sentry\EventHint $hint = null, ?\Sentry\State\Scope $scope = null) : ?\Sentry\EventId
    {
        $event = $this->prepareEvent($event, $hint, $scope);
        if ($event === null) {
            return null;
        }
        try {
            /** @var Result $result */
            $result = $this->transport->send($event);
            $event = $result->getEvent();
            if ($event !== null) {
                return $event->getId();
            }
        } catch (\Throwable $exception) {
            $this->logger->error(\sprintf('Failed to send the event to Sentry. Reason: "%s".', $exception->getMessage()), ['exception' => $exception, 'event' => $event]);
        }
        return null;
    }
    /**
     * {@inheritdoc}
     */
    public function captureLastError(?\Sentry\State\Scope $scope = null, ?\Sentry\EventHint $hint = null) : ?\Sentry\EventId
    {
        $error = \error_get_last();
        if ($error === null || !isset($error['message'][0])) {
            return null;
        }
        $exception = new \ErrorException(@$error['message'], 0, @$error['type'], @$error['file'], @$error['line']);
        return $this->captureException($exception, $scope, $hint);
    }
    /**
     * {@inheritdoc}
     *
     * @psalm-template T of IntegrationInterface
     */
    public function getIntegration(string $className) : ?\Sentry\Integration\IntegrationInterface
    {
        /** @psalm-var T|null */
        return $this->integrations[$className] ?? null;
    }
    /**
     * {@inheritdoc}
     */
    public function flush(?int $timeout = null) : \Sentry\Transport\Result
    {
        return $this->transport->close($timeout);
    }
    /**
     * {@inheritdoc}
     */
    public function getStacktraceBuilder() : \Sentry\StacktraceBuilder
    {
        return $this->stacktraceBuilder;
    }
    /**
     * @internal
     */
    public function getLogger() : \WPSentry\ScopedVendor\Psr\Log\LoggerInterface
    {
        return $this->logger;
    }
    /**
     * @internal
     */
    public function getTransport() : \Sentry\Transport\TransportInterface
    {
        return $this->transport;
    }
    /**
     * Assembles an event and prepares it to be sent of to Sentry.
     *
     * @param Event          $event The payload that will be converted to an Event
     * @param EventHint|null $hint  May contain additional information about the event
     * @param Scope|null     $scope Optional scope which enriches the Event
     *
     * @return Event|null The prepared event object or null if it must be discarded
     */
    private function prepareEvent(\Sentry\Event $event, ?\Sentry\EventHint $hint = null, ?\Sentry\State\Scope $scope = null) : ?\Sentry\Event
    {
        if ($hint !== null) {
            if ($hint->exception !== null && empty($event->getExceptions())) {
                $this->addThrowableToEvent($event, $hint->exception, $hint);
            }
            if ($hint->stacktrace !== null && $event->getStacktrace() === null) {
                $event->setStacktrace($hint->stacktrace);
            }
        }
        $this->addMissingStacktraceToEvent($event);
        $event->setSdkIdentifier($this->sdkIdentifier);
        $event->setSdkVersion($this->sdkVersion);
        $event->setTags(\array_merge($this->options->getTags(), $event->getTags()));
        if ($event->getServerName() === null) {
            $event->setServerName($this->options->getServerName());
        }
        if ($event->getRelease() === null) {
            $event->setRelease($this->options->getRelease());
        }
        if ($event->getEnvironment() === null) {
            $event->setEnvironment($this->options->getEnvironment() ?? \Sentry\Event::DEFAULT_ENVIRONMENT);
        }
        $eventDescription = \sprintf('%s%s [%s]', $event->getLevel() !== null ? $event->getLevel() . ' ' : '', (string) $event->getType(), (string) $event->getId());
        $isEvent = \Sentry\EventType::event() === $event->getType();
        $sampleRate = $this->options->getSampleRate();
        // only sample with the `sample_rate` on errors/messages
        if ($isEvent && $sampleRate < 1 && \mt_rand(1, 100) / 100.0 > $sampleRate) {
            $this->logger->info(\sprintf('The %s will be discarded because it has been sampled.', $eventDescription), ['event' => $event]);
            return null;
        }
        $event = $this->applyIgnoreOptions($event, $eventDescription);
        if ($event === null) {
            return null;
        }
        if ($scope !== null) {
            $beforeEventProcessors = $event;
            $event = $scope->applyToEvent($event, $hint, $this->options);
            if ($event === null) {
                $this->logger->info(\sprintf('The %s will be discarded because one of the event processors returned "null".', $eventDescription), ['event' => $beforeEventProcessors]);
                return null;
            }
        }
        $beforeSendCallback = $event;
        $event = $this->applyBeforeSendCallback($event, $hint);
        if ($event === null) {
            $this->logger->info(\sprintf('The %s will be discarded because the "%s" callback returned "null".', $eventDescription, $this->getBeforeSendCallbackName($beforeSendCallback)), ['event' => $beforeSendCallback]);
        }
        return $event;
    }
    private function isIgnoredException(string $className) : bool
    {
        foreach ($this->options->getIgnoreExceptions() as $ignoredException) {
            if (\is_a($className, $ignoredException, \true)) {
                return \true;
            }
        }
        return \false;
    }
    private function applyIgnoreOptions(\Sentry\Event $event, string $eventDescription) : ?\Sentry\Event
    {
        if ($event->getType() === \Sentry\EventType::event()) {
            $exceptions = $event->getExceptions();
            if (empty($exceptions)) {
                return $event;
            }
            foreach ($exceptions as $exception) {
                if ($this->isIgnoredException($exception->getType())) {
                    $this->logger->info(\sprintf('The %s will be discarded because it matches an entry in "ignore_exceptions".', $eventDescription), ['event' => $event]);
                    return null;
                }
            }
        }
        if ($event->getType() === \Sentry\EventType::transaction()) {
            $transactionName = $event->getTransaction();
            if ($transactionName === null) {
                return $event;
            }
            if (\in_array($transactionName, $this->options->getIgnoreTransactions(), \true)) {
                $this->logger->info(\sprintf('The %s will be discarded because it matches a entry in "ignore_transactions".', $eventDescription), ['event' => $event]);
                return null;
            }
        }
        return $event;
    }
    private function applyBeforeSendCallback(\Sentry\Event $event, ?\Sentry\EventHint $hint) : ?\Sentry\Event
    {
        switch ($event->getType()) {
            case \Sentry\EventType::event():
                return $this->options->getBeforeSendCallback()($event, $hint);
            case \Sentry\EventType::transaction():
                return $this->options->getBeforeSendTransactionCallback()($event, $hint);
            case \Sentry\EventType::checkIn():
                return $this->options->getBeforeSendCheckInCallback()($event, $hint);
            case \Sentry\EventType::metrics():
                return $this->options->getBeforeSendMetricsCallback()($event, $hint);
            default:
                return $event;
        }
    }
    private function getBeforeSendCallbackName(\Sentry\Event $event) : string
    {
        switch ($event->getType()) {
            case \Sentry\EventType::transaction():
                return 'before_send_transaction';
            case \Sentry\EventType::checkIn():
                return 'before_send_check_in';
            case \Sentry\EventType::metrics():
                return 'before_send_metrics';
            default:
                return 'before_send';
        }
    }
    /**
     * Optionally adds a missing stacktrace to the Event if the client is configured to do so.
     *
     * @param Event $event The Event to add the missing stacktrace to
     */
    private function addMissingStacktraceToEvent(\Sentry\Event $event) : void
    {
        if (!$this->options->shouldAttachStacktrace()) {
            return;
        }
        // We should not add a stacktrace when the event already has one or contains exceptions
        if ($event->getStacktrace() !== null || !empty($event->getExceptions())) {
            return;
        }
        $event->setStacktrace($this->stacktraceBuilder->buildFromBacktrace(\debug_backtrace(0), __FILE__, __LINE__ - 3));
    }
    /**
     * Stores the given exception in the passed event.
     *
     * @param Event      $event     The event that will be enriched with the exception
     * @param \Throwable $exception The exception that will be processed and added to the event
     * @param EventHint  $hint      Contains additional information about the event
     */
    private function addThrowableToEvent(\Sentry\Event $event, \Throwable $exception, \Sentry\EventHint $hint) : void
    {
        if ($exception instanceof \ErrorException && $event->getLevel() === null) {
            $event->setLevel(\Sentry\Severity::fromError($exception->getSeverity()));
        }
        $exceptions = [];
        do {
            $exceptions[] = new \Sentry\ExceptionDataBag($exception, $this->stacktraceBuilder->buildFromException($exception), $hint->mechanism ?? new \Sentry\ExceptionMechanism(\Sentry\ExceptionMechanism::TYPE_GENERIC, \true, ['code' => $exception->getCode()]));
        } while ($exception = $exception->getPrevious());
        $event->setExceptions($exceptions);
    }
}
