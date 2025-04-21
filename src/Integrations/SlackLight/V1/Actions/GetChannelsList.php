<?php

namespace App\Integrations\SlackLight\V1\Actions;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\AliasMapper;
use App\Integrations\SlackLight\V1\Service\SlackLight;
use App\Intrastructure\Persistence\IntegrationRepository;
use DI\Container;

class GetChannelsList extends \App\Integrations\Slack\V2\Actions\GetChannelsList
{
    private SlackLight $slackLight;

    public function __construct(
        AliasMapper                $fakeIntegrationNameMapper,
        IntegrationLoggerInterface $logger,
        Container                  $container,
        IntegrationRepository      $integrationRepository,
        SlackLight                 $slackLight
    )
    {
        parent::__construct($fakeIntegrationNameMapper, $logger, $container, $integrationRepository, $slackLight);
    }
}
