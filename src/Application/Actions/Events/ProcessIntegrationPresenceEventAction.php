<?php

namespace App\Application\Actions\Events;

use App\Application\Actions\IntegrationAction;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\AliasMapper;
use App\Domain\PresenceEvent\PresenceEvent;
use App\Domain\PresenceEvent\Service\PresenceEventCreation;
use App\Integrations\Service\AbstractProcessPresenceEvent;
use App\Intrastructure\Persistence\IntegrationRepository;
use DI\Container;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;

class ProcessIntegrationPresenceEventAction extends IntegrationAction
{
    private PresenceEventCreation $presenceEventCreation;

    public function __construct(
        AliasMapper                $fakeIntegrationNameMapper,
        IntegrationLoggerInterface $logger,
        Container                  $container,
        IntegrationRepository      $integrationRepository,
        PresenceEventCreation      $presenceEventCreation
    ) {
        parent::__construct($fakeIntegrationNameMapper, $logger, $container, $integrationRepository);
        $this->presenceEventCreation = $presenceEventCreation;
    }

    /**
     * Retourne un objet d'évènement de présence
     * @throws Exception
     */
    private function getPresenceEvent(): PresenceEvent
    {
        $presenceEvent = $this->presenceEventCreation->createPresenceFromRawEvent(
            $this->request->getAttribute('team_id'),
            $this->request->getAttribute('user_id'),
            $this->request->getParsedBody()
        );

        if (empty($presenceEvent)) {
            $this->logger->integrationLog(
                'PRESENCE_SERVICE_ENTITY',
                "Impossible de créer l'objet qui représente la PRESENCE"
            );
            throw new Exception("Bad parameter");
        }

        return $presenceEvent;
    }

    protected function action(): Response
    {
        try {
            $this->logger->debug(
                'PRESENCE_EVENT', [
                    'class' => ProcessIntegrationPresenceEventAction::class,
                    'payload' => $this->request->getParsedBody()
                ]
            );
            /** @var AbstractProcessPresenceEvent $processPresenceEvent */
            $processPresenceEvent = $this->getProcessIntegrationService(
                'ProcessPresenceEvent'
            );

            if (empty($processPresenceEvent)) {
                return $this->response->withStatus(404, 'Service ListDropDown not found');
            }

            $processPresenceEvent->__process(
                $this->integration,
                $this->getPresenceEvent()
            );
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                $exception->getMessage() ?? '',
                0 < $exception->getCode() ? $exception->getCode() : 500
            );
        }

        return $this->response;
    }
}
