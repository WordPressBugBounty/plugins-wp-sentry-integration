<?php

declare (strict_types=1);
namespace Sentry\Integration;

use WPSentry\ScopedVendor\Psr\Log\LoggerInterface;
use Sentry\Options;
/**
 * @internal
 */
final class IntegrationRegistry
{
    /**
     * @var self|null The current instance
     */
    private static $instance;
    /**
     * @var array<class-string<IntegrationInterface>, bool> The registered integrations
     */
    private $integrations = [];
    private function __construct()
    {
    }
    /**
     * Gets the current singleton instance or creates a new one if it didn't
     * exists yet.
     */
    public static function getInstance() : self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    /**
     * Setups the integrations according to the given options. For each integration
     * the {@see IntegrationInterface::setupOnce()} method will be called only once
     * during the application lifetime.
     *
     * @param Options $options The SDK client options
     *
     * @return array<class-string<IntegrationInterface>, IntegrationInterface>
     */
    public function setupIntegrations(\Sentry\Options $options, \WPSentry\ScopedVendor\Psr\Log\LoggerInterface $logger) : array
    {
        $integrations = [];
        $installed = [];
        foreach ($this->getIntegrationsToSetup($options) as $integration) {
            $integrationName = \get_class($integration);
            $integrations[$integrationName] = $integration;
            if ($this->setupIntegration($integration, $options)) {
                $installed[] = $integrationName;
            }
        }
        if (\count($installed) > 0) {
            $logger->debug(\sprintf('The "%s" integration(s) have been installed.', \implode(', ', $installed)));
        }
        return $integrations;
    }
    private function setupIntegration(\Sentry\Integration\IntegrationInterface $integration, \Sentry\Options $options) : bool
    {
        $integrationName = \get_class($integration);
        if (isset($this->integrations[$integrationName])) {
            return \false;
        }
        if ($integration instanceof \Sentry\Integration\OptionAwareIntegrationInterface) {
            $integration->setOptions($options);
        }
        $integration->setupOnce();
        $this->integrations[$integrationName] = \true;
        return \true;
    }
    /**
     * @return IntegrationInterface[]
     */
    private function getIntegrationsToSetup(\Sentry\Options $options) : array
    {
        $integrations = [];
        $defaultIntegrations = $this->getDefaultIntegrations($options);
        $userIntegrations = $options->getIntegrations();
        if (\is_array($userIntegrations)) {
            $userIntegrationsClasses = \array_map('get_class', $userIntegrations);
            $pickedIntegrationsClasses = [];
            foreach ($defaultIntegrations as $defaultIntegration) {
                $integrationClassName = \get_class($defaultIntegration);
                if (!\in_array($integrationClassName, $userIntegrationsClasses, \true) && !isset($pickedIntegrationsClasses[$integrationClassName])) {
                    $integrations[] = $defaultIntegration;
                    $pickedIntegrationsClasses[$integrationClassName] = \true;
                }
            }
            foreach ($userIntegrations as $userIntegration) {
                $integrationClassName = \get_class($userIntegration);
                if (!isset($pickedIntegrationsClasses[$integrationClassName])) {
                    $integrations[] = $userIntegration;
                    $pickedIntegrationsClasses[$integrationClassName] = \true;
                }
            }
        } else {
            $integrations = $userIntegrations($defaultIntegrations);
            if (!\is_array($integrations)) {
                throw new \UnexpectedValueException(\sprintf('Expected the callback set for the "integrations" option to return a list of integrations. Got: "%s".', \get_debug_type($integrations)));
            }
        }
        return $integrations;
    }
    /**
     * @return IntegrationInterface[]
     */
    private function getDefaultIntegrations(\Sentry\Options $options) : array
    {
        if (!$options->hasDefaultIntegrations()) {
            return [];
        }
        $integrations = [new \Sentry\Integration\RequestIntegration(), new \Sentry\Integration\TransactionIntegration(), new \Sentry\Integration\FrameContextifierIntegration(), new \Sentry\Integration\EnvironmentIntegration(), new \Sentry\Integration\ModulesIntegration()];
        if ($options->getDsn() !== null) {
            \array_unshift($integrations, new \Sentry\Integration\ExceptionListenerIntegration(), new \Sentry\Integration\ErrorListenerIntegration(), new \Sentry\Integration\FatalErrorListenerIntegration());
        }
        return $integrations;
    }
}
