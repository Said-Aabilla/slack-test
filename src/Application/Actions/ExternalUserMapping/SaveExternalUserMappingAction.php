<?php

namespace App\Application\Actions\ExternalUserMapping;

use App\Application\Actions\IntegrationAction;
use DateTime;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class SaveExternalUserMappingAction extends IntegrationAction
{
    /**
     * @return Response
     */
    protected function action(): Response
    {
        $configuration = $this->generateExternalUserMappingFromBodyRequest();
        $this->integrationRepository->saveIntegrationConfigurationAsJson(
            $this->integration->getId(),
            $configuration,
            'ringover_user_to_external'
        );
        $this->response->getBody()->write(json_encode($configuration));
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }


    /**
     * Generates external user mapping from the request body.
     *
     * @return array The generated external user mapping.
     * @throws HttpBadRequestException If the request body is invalid or contains invalid data.
     */
    private function generateExternalUserMappingFromBodyRequest() : array
    {
        $body = $this->request->getParsedBody();
        if (is_null($body)) {
            throw new HttpBadRequestException($this->request, 'Invalid body request given');
        }
        $this->checkRequiredProperties($body, ['users']);
        $date = (new DateTime())->format('Y-m-d H:i:s');
        $isUserFields = isset($body['user_fields']);

        $ringoverUserToExternalConfig =  $this->integration->getConfiguration()['ringover_user_to_external'];
        $isConfigUserFields = !empty($ringoverUserToExternalConfig['user_fields']);

        $newConfiguration = [
            'users' => $this->generateUserConfiguration($body, $isUserFields, $date)
        ];
        $disabledUserConfiguration = $this->generateDisabledUserConfiguration($body, $date);
        if (!empty($disabledUserConfiguration)) {
            $newConfiguration['disabled_users'] = $disabledUserConfiguration;
        }
        if ($isUserFields) {
            $newConfiguration['user_fields'] = $body['user_fields'];
        } elseif ($isConfigUserFields) {
            $newConfiguration['user_fields'] = $ringoverUserToExternalConfig['user_fields'];
        }
        return $newConfiguration;
    }

    /**
     * @param array $body
     * @param bool $isUserFields
     * @param string $date
     * @return array
     */
    protected function generateUserConfiguration(array $body, bool $isUserFields, string $date) : array
    {
        $ringoverUserExternalList = $this->integration->getConfiguration()['ringover_user_to_external']['users'] ?? [];
        $manuelUserConfiguration = [];
        foreach ($body['users'] as $item) {
            if ($isUserFields) {
                $this->checkManuelUser($item, ['user_id', 'user_field_value'], $manuelUserConfiguration);
                $manuelUserConfiguration[$item['user_id']] = $item['user_field_value'];
            } else {
                $this->checkManuelUser($item, ['user_id', 'external_id'], $manuelUserConfiguration);
                $manuelUserConfiguration[$item['user_id']] = [
                    'externalId' => $item['external_id'],
                    'mappingDate' => $ringoverUserExternalList[$item['user_id']]['mapping_date'] ?? $date
                ];
            }
        }
        return $manuelUserConfiguration;
    }

    /**
     * @param array $body
     * @param string $date
     * @return array
     */
    private function generateDisabledUserConfiguration(array $body, string $date) : array
    {
        $disabledUserConfiguration = [];
        if (!empty($body['disabled_users'])) {
            $ringoverDisabledUserList = $this->integration->getConfiguration()['ringover_user_to_external']
                ['disabled_users'] ?? [];
            foreach ($body['disabled_users'] as $disabledUser) {
                $this->checkRequiredProperties($disabledUser, ['user_id', 'external_id']);
                $disabledUserConfiguration[$disabledUser['user_id']] = [
                    'externalId' => $disabledUser['external_id'],
                    'disabledDate' => $ringoverDisabledUserList[$disabledUser['user_id']]['disabledDate'] ?? $date
                ];
            }
        }
        return $disabledUserConfiguration;
    }

    /**
     * @param array $item
     * @param array $requiredProperties
     * @param array $manuelUserConfiguration
     * @return void
     */
    protected function checkManuelUser(array $item, array $requiredProperties, array $manuelUserConfiguration) : void
    {
        $this->checkRequiredProperties($item, $requiredProperties);
        if (isset($manuelUserConfiguration[$item['user_id']])) {
            $exception =  new HttpBadRequestException($this->request, 'Duplicate user : ' . $item['user_id']);
            $exception->setTitle('duplicate_user');
            throw $exception;
        }
    }
}
