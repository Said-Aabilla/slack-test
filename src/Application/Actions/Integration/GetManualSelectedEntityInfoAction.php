<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationAction;
use App\Integrations\Service\AbstractGetManualSelectedEntityInfo;
use App\Integrations\Service\GenericGetManualSelectedEntityInfo;
use App\Domain\Integration\Integration;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;

class GetManualSelectedEntityInfoAction extends IntegrationAction
{
    protected function action(): Response
    {
        $entityType = $this->request->getAttribute('entity_type');
        $entityId   = $this->request->getAttribute('entity_id');
        try {
            /** @var AbstractGetManualSelectedEntityInfo $getManualSelectContactInfoService */
            $getManualSelectContactInfoService = $this->getProcessIntegrationService(
                'GetManualSelectedEntityInfo',
                GenericGetManualSelectedEntityInfo::class
            );

            if (empty($getManualSelectContactInfoService)) {
                return $this->response->withStatus(404, 'Service not found');
            }

            $contactInfo = $getManualSelectContactInfoService->__process(
                $this->integration,
                $entityType,
                $entityId,
                $this->request->getQueryParams()
            );
        } catch (Exception $exception) {
            $this->logger->error('GET_MANUAL_SELECTED_CONTACT_INFO_ ' . $exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                $exception->getMessage() ?? '',
                $exception->getCode() ?? 500
            );
        }

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write(json_encode($contactInfo));
        return $this->response->withBody($body);
    }

    /**
     * Get user/team integration service from given request
     * @return Integration
     * @throws Exception
     * @override Current action doesn't require team_id as an attribute
     */
    public function getUserIntegrationFromRequest(): Integration
    {
        if (
            empty($this->request->getAttribute('integration_name')) &&
            empty($this->request->getAttribute('integration_id'))
        ) {
            throw new HttpBadRequestException($this->request, 'Empty integration name or id given');
        }

        if (!empty($this->request->getAttribute('integration_id'))) {
            $integration = $this->integrationRepository->getIntegrationById(
                $this->request->getAttribute('integration_id')
            );

            if (!$integration) {
                throw new HttpNotFoundException($this->request, 'No integration found for this id');
            }

            /*if ($integration->getTeamId() !== (int)$this->request->getAttribute('team_id')) {
                throw new HttpNotFoundException($this->request, 'No integration found for this team');
            }*/
            return $integration;
        } else {
            $serviceName = strtoupper($this->request->getAttribute('integration_name'));
            $integrations = $this->integrationRepository->getTeamIntegrationsData(
                $this->request->getAttribute('team_id'),
                [$serviceName],
                true
            );
            if (!isset($integrations[$serviceName])) {
                throw new HttpNotFoundException($this->request, 'No integration found for this user');
            }
            return $integrations[$serviceName];
        }
    }
}
