<?php

namespace App\Integrations\Service;

use App\Domain\IntegrationFields\DTO\IntegrationFieldValueDTO;
use App\Domain\Integration\Integration;
use App\Domain\Integration\IntegrationContactIdentity;

abstract class AbstractGetManualSelectedEntityInfo extends AbstractProcess
{
    private ?AbstractIntegrationFields $integrationFieldsService = null;

    public function __construct(
        ?AbstractIntegrationFields $integrationFields = null
    ) {
        $this->integrationFieldsService = $integrationFields;
    }

    /**
     * @param Integration $integration
     * @param string $entityType
     * @param string $entityId
     * @param array $queryParams
     * @return array
     */
    public function __process(
        Integration $integration,
        string $entityType,
        string $entityId,
        array $queryParams
    ): array {
        $this->integration = $integration;
        return $this->process($entityType, $entityId, $queryParams);
    }

    /**
     * Récupérer et convertir un contact en objet IntegrationContactIdentity
     * @param string $entityType
     * @param string $entityId
     * @return IntegrationContactIdentity|null
     */
    abstract public function getIntegrationContactIdentity(
        string $entityType,
        string $entityId
    ): ?IntegrationContactIdentity;

    private function process(
        string $entityType,
        string $entityId,
        array $queryParams
    ): array {
        $extraData     = !empty($queryParams['with']) ? explode(',', trim($queryParams['with'])) : [];
        $withCrmFields = in_array('crm_fields', $extraData);

        // Cherche de contact sur l'outil métier
        $integrationContactIdentity = $this->getIntegrationContactIdentity($entityType, $entityId);

        // Contact non trouvé
        if (null === $integrationContactIdentity) {
            return [];
        }

        // Ajoute des infos liées à l'intégration
        $integrationContactIdentity->integrationId   = $this->integration->getId();
        $integrationContactIdentity->integrationName = $this->integrationService->getIntegrationName();

        // Retourne tableau d'info par défaut
        if (!$withCrmFields || null === $this->integrationFieldsService) {
            return $integrationContactIdentity->jsonSerialize();
        }

        // Générer des valeurs de CRM fields
        $this->integrationFieldsService->setIntegration($this->integration);
        /** @var IntegrationFieldValueDTO $crmFieldsValuesData */
        $crmFieldsValuesData = $this->integrationFieldsService
            ->getObjectFieldsValue($integrationContactIdentity)->social_data;

        // Retourne tableau d'info avec des champs CRM
        return array_merge(
            $integrationContactIdentity->jsonSerialize(),
            ['crm_fields' => $crmFieldsValuesData]
        );
    }
}
