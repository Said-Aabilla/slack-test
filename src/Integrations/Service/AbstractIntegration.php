<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Intrastructure\Service\HttpClient;
use App\Settings\SettingsInterface;

abstract class AbstractIntegration
{
    /**
     * @var HttpClient
     */
    protected HttpClient $httpClient;
    protected SettingsInterface $settings;
    protected IntegrationLoggerInterface $logger;

    protected string $alias = '';

    /**
     * @param HttpClient $httpClient
     * @param SettingsInterface $settings
     * @param IntegrationLoggerInterface $logger
     */
    public function __construct(
        HttpClient                 $httpClient,
        SettingsInterface          $settings,
        IntegrationLoggerInterface $logger
    ) {
        $httpClient->setIntegrationName($this->getIntegrationName());
        $logger->pushProcessor(
            function ($records) {
                if (!empty($this->getIntegrationName())) {
                    $records['extra']['integration_name'] = $this->getIntegrationName();
                }

                return $records;
            }
        );

        $this->httpClient = $httpClient;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function setAlias(string $alias): void
    {
        $this->alias = $alias;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Retourne le nom de l'intégration
     * @return string
     */
    abstract public function getIntegrationName(): string;
}
