<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\CallEvent\Call;
use App\Domain\CallEvent\CallStatus;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Domain\IntegrationFields\DTO\IntegrationFieldPropertyDTO;
use App\Domain\IntegrationFields\DTO\IntegrationFieldValueDTO;
use App\Domain\IntegrationFields\DTO\IntegrationObjectFieldsValuesDTO;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Intrastructure\Service\WebsocketClient;

abstract class AbstractIntegrationFields extends AbstractProcess
{
    private WebsocketClient $websocketClient;

    public function __construct(
        IntegrationRepository      $integrationRepository,
        IntegrationLoggerInterface $logger,
        WebsocketClient            $websocketClient
    )
    {
        parent::__construct($integrationRepository, $logger);
        $this->websocketClient = $websocketClient;
    }


    /**
     * Get the list of the fields (properties) by the given object name
     * @param string $objectName
     * @return array
     */
    abstract public function getObjectFieldsMetadata(string $objectName): array;

    /**
     * Get the information of object from a given fields list
     * @param IntegrationContactIdentity $contactIdentity
     * @param IntegrationFieldPropertyDTO[] $integrationFieldsProperty
     * @return IntegrationFieldValueDTO[]
     */
    abstract public function getObjectFieldsValuesById(
        IntegrationContactIdentity $contactIdentity,
        array                      $integrationFieldsProperty
    ): array;


    /**
     * Get the list of supported objects
     * @return array
     */
    abstract public function getObjectList(): array;

    /**
     * Send integration object fields via websocket
     * @param IntegrationContactIdentity $contactIdentity
     * @param Call $call
     * @return bool
     */
    public function sendCallFieldsInformationWebSocket(
        IntegrationContactIdentity $contactIdentity,
        Call                       $call
    ): bool
    {
        if ($this->isCallFieldsInformationSendable($call)) {
            $fields = $this->getObjectFieldsValue($contactIdentity);
            $this->logger->integrationLog('INTEGRATION_FIELDS_SEND', 'New integration fields', [
                'fields' => $fields,
                'configuration' => $this->integration->getConfiguration()['objectsFieldsMetadata'] ?? []
            ]);
            if (empty($fields->social_data)) {
                return false;
            }
            return $this->websocketClient->sendMessageToUserByUserToken(
                $call->firstRingoverUser['token'],
                'call-contact-data',
                [
                    'fields' => $fields,
                    'call_id' => $call->callId
                ]
            );
        }
        return false;
    }

    /**
     * Send integration object fields via websocket for multiple contact list
     * @param IntegrationContactIdentity[] $contactsIdentity
     * @param Call $call
     * @return void
     */
    public function sendCallFieldsInformationWebSocketForMultipleContacts(array $contactsIdentity, Call $call): bool
    {
        if ($this->isCallFieldsInformationSendable($call)) {
            $fields = new IntegrationObjectFieldsValuesDTO([], $this->integration->getIntegrationName());
            $objectsFieldsMetadata = $this->integration->getConfiguration()['objectsFieldsMetadata'] ?? [];
            $integrationFieldProperties = $this->generateIntegrationFieldProperties($objectsFieldsMetadata);
            foreach ($contactsIdentity as $contactIdentity) {
                $fields->social_data = array_merge_recursive($fields->social_data, $this->getObjectFieldsValuesById(
                    $contactIdentity,
                    $integrationFieldProperties
                ));
            }
            if (empty($fields->social_data)) {
                return false;
            }
            return $this->websocketClient->sendMessageToUserByUserToken(
                $call->firstRingoverUser['token'],
                'call-contact-data',
                [
                    'fields' => $fields,
                    'call_id' => $call->callId
                ]
            );
        }
        return false;
    }

    /**
     * @param IntegrationContactIdentity $contactIdentity
     * @return IntegrationObjectFieldsValuesDTO
     */
    public function getObjectFieldsValue(IntegrationContactIdentity $contactIdentity): IntegrationObjectFieldsValuesDTO
    {
        $objectsFieldsMetadata =  $this->integration->getConfiguration()['objectsFieldsMetadata'] ?? [];
        return new IntegrationObjectFieldsValuesDTO(
            $this->getObjectFieldsValuesById(
                $contactIdentity,
                $this->generateIntegrationFieldProperties(
                    $objectsFieldsMetadata
                )
            ),
            $this->integration->getIntegrationName()
        );
    }


    /**
     * @param array $configuration
     * @return IntegrationFieldPropertyDTO[]
     */
    private function generateIntegrationFieldProperties(array $configuration): array
    {
        return array_map(function ($field) {
            return new IntegrationFieldPropertyDTO(
                $field['label'],
                $field['property'],
                $field['object'],
                $field['description']
            );
        }, $configuration);
    }

    /**
     * Generate a string list of property for given object from IntegrationFieldPropertyDTO
     * @param string $object
     * @param IntegrationFieldPropertyDTO[] $integrationFieldsProperty
     * @return array
     */
    protected function generatePropertyList(string $object, array $integrationFieldsProperty): array
    {
        $properties = [];
        foreach ($integrationFieldsProperty as $integrationFieldProperty) {
            if ($integrationFieldProperty->object === $object) {
                $properties[] = $integrationFieldProperty->property;
            }
        }
        return $properties;
    }

    /**
     * Check if call fields information can be sent or not
     * @param Call $call
     * @return bool
     */
    private function isCallFieldsInformationSendable(Call $call): bool
    {

        return !empty($this->integration->getConfiguration()['objectsFieldsMetadata']) &&
            $call->status === CallStatus::INCALL ||
            (
                $call->status === CallStatus::DIALED &&
                in_array($call->customerStatus, ['success', 'ringing']) &&
                $call->agentStatus === 'success'
            ) ||
            (
                $call->status === CallStatus::INCOMING &&
                in_array($call->agentStatus, ['success', 'ringing']) &&
                $call->customerStatus === 'success'
            );
    }
}
