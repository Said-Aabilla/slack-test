<?php

namespace App\Application\Actions\Events;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\Integration;
use App\Domain\OmnichannelEvent\OmnichannelEventType;
use App\Domain\OmnichannelEvent\OmnichannelName;
use App\Domain\OmnichannelEvent\Service\OmnichannelEventCreation;
use App\Integrations\Service\AbstractProcessOmnichannelEvent;
use App\Intrastructure\Persistence\IntegrationRepository;
use DI\Container;
use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;

class ProcessOmnichannelEventAction extends Action
{
    private OmnichannelEventCreation $omnichannelCreation;
    private IntegrationRepository $integrationRepository;
    private Container $container;

    public function __construct(
        IntegrationLoggerInterface $logger,
        Container                  $container,
        IntegrationRepository      $integrationRepository,
        OmnichannelEventCreation   $omnichannelCreation
    ) {
        parent::__construct($logger);
        $this->omnichannelCreation = $omnichannelCreation;
        $this->container = $container;
        $this->integrationRepository = $integrationRepository;
    }

    /**
     * @throws Exception
     */
    protected function action(): Response
    {
        $body = $this->request->getParsedBody();
        $this->checkRequiredProperties($body, ['conversation', 'data']);
        $this->checkRequiredProperties($body['data'], ['channel', 'type', 'event_data']);
        if ($body['data']['channel'] != OmnichannelName::WHATSAPP) {
            return $this->response->withStatus(
                StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY,
                'Channel unsupported : ' . $body['data']['channel'],
            );
        }
        if (!in_array($body['data']['type'], [OmnichannelEventType::NEW_MESSAGE, OmnichannelEventType::DELETE_MESSAGE])) {
            return $this->response->withStatus(
                StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY,
                'Type unsupported : ' . $body['data']['type']
            );
        }
        $integrations = $this->integrationRepository->getTeamIntegrationsData(
            $this->request->getAttribute('team_id'),
            [],
            true
        );
        if (empty($integrations)) {
            return $this->response->withStatus(StatusCodeInterface::STATUS_NO_CONTENT, 'Integration not found');
        }
        $omnichannelEvent = $this->omnichannelCreation->createOmnichannelEventFromRaw(
            $body['data'],
            $body['conversation'],
            $this->request->getAttribute('team_id'),
            $this->request->getAttribute('user_id')
        );
        foreach ($integrations as $integration) {
            $omnichannelEventProcess = $this->getIntegrationOmnichannelEventService($integration);
            if (!is_null($omnichannelEventProcess)) {
                $this->logger->integrationLog(
                    'OMNICHANNEL_EVENT',
                    'RUN OMNICHANNEL EVENT',
                    [
                        'integration' =>  $integration->getIntegrationName(),
                        'conversation_uuid' => $omnichannelEvent->omnichannelConversation->getUuid(),
                        'message_uuid' => $omnichannelEvent->eventData['message']['uuid'] ?? null
                    ]
                );
                $omnichannelEventProcess->process(
                    $integration,
                    $omnichannelEvent
                );
            }
        }
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }

    /**
     * Get integration omnichannel service from given integration and request
     * @param Integration $integration
     * @return AbstractProcessOmnichannelEvent
     */
    private function getIntegrationOmnichannelEventService(Integration $integration): ?AbstractProcessOmnichannelEvent
    {
        try {
            /** @var AbstractProcessOmnichannelEvent $integrationService */
            $integrationService = $this->container->get(
                $integration->getNamespace() . '\Service\ProcessOmnichannelEvent'
            );
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return null;
        }
        if (!($integrationService instanceof AbstractProcessOmnichannelEvent)) {
            return null;
        }
        return $integrationService;
    }
}