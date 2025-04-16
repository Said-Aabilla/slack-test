<?php

namespace App\Integrations\Service;

use App\Domain\CallEvent\Call;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\Integration;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Intrastructure\Persistence\UserRepository;
use DI\Container;

abstract class AbstractProcess
{
    /**
     * @var \App\Application\Logger\IntegrationLoggerInterface
     */
    protected IntegrationLoggerInterface $logger;

    /**
     * @var \App\Domain\CallEvent\Call
     */
    protected Call $callEvent;

    /** @var \App\Domain\Integration\Integration */
    protected Integration $integration;

    /** @var \App\Integrations\Service\AbstractIntegration */
    protected AbstractIntegration $integrationService;

    /**
     * @var \App\Intrastructure\Persistence\IntegrationRepository
     */
    protected IntegrationRepository $integrationRepository;

    /**
     * @param \App\Intrastructure\Persistence\IntegrationRepository $integrationRepository
     * @param \App\Application\Logger\IntegrationLoggerInterface $logger
     */
    public function __construct(
        IntegrationRepository $integrationRepository,
        IntegrationLoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->integrationRepository = $integrationRepository;
    }

    public function setIntegrationService(AbstractIntegration $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    public function setIntegration(Integration $integration)
    {
        $this->integration = $integration;
    }
}
