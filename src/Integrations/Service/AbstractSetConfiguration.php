<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\ContactSync\Service\ManageSync;
use App\Domain\Exception\ContactSyncException;
use App\Domain\Integration\Integration;
use App\Domain\Integration\Service\SetConfigurationContactTrait;
use App\Integrations\AccessRecruitment\V1\Service\AccessRecruitment;
use App\Integrations\BoondManager\V1\Service\BoondManager;
use App\Integrations\Freshservice\V1\Service\Freshservice;
use App\Integrations\InesCrm\V1\Service\InesCrm;
use App\Integrations\Servicenow\V1\Service\Servicenow;
use App\Integrations\Tempworks\V1\Service\Tempworks;
use App\Integrations\Zendesk\V2\Service\Zendesk;
use App\Integrations\ZohoCrm\V1\Service\ZohoCrm;
use App\Integrations\ZohoDesk\V1\Service\ZohoDesk;
use App\Integrations\ZohoRecruit\V1\Service\ZohoRecruit;
use App\Integrations\ActiveCampaign\V2\Service\ActiveCampaign;
use App\Integrations\Crelate\V1\Service\Crelate;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Intrastructure\Persistence\UserRepository;

abstract class AbstractSetConfiguration extends AbstractProcess
{
    use SetConfigurationContactTrait;


    private ManageSync $contactSyncService;
    private UserRepository $userRepository;

    /**
     * @param IntegrationRepository $integrationRepository
     * @param IntegrationLoggerInterface $logger
     * @param ManageSync $contactSyncService // Used by SetConfigurationContactTrait
     * @param UserRepository $userRepository // Used by SetConfigurationContactTrait
     */
    public function __construct(
        IntegrationRepository      $integrationRepository,
        IntegrationLoggerInterface $logger,
        ManageSync                 $contactSyncService,
        UserRepository             $userRepository
    ) {
        parent::__construct($integrationRepository, $logger);
        $this->contactSyncService = $contactSyncService;
        $this->userRepository = $userRepository;
    }

    private const CONF_BOOL_VAL = [
        'syncContactEnabledV4',
        'onUsageSyncEnabled',
        'logOmnichannelEvent',
        'createOmnichannelContact',
        'omnichannelOnSeparateLog',
        'withPrivateRecord',
        'automaticCallTypeTagCorrespondence',
        'automaticOutcomeCallTagCorrespondence',
        'linkCallToDeal',
        'direct_search',
        'create_tickets_for_people'
    ];
    private const CONF_TO_PRESERVE = [
        'syncUserId',
        'syncCC',
        ['callType', 'in', 'incall'],
        ['callType', 'out', 'incall'],
        'webhook_agent_status'
    ];
    private const CONF_SYNC_CONTACT = [
        Freshservice::INTEGRATION_NAME,
        Crelate::INTEGRATION_NAME,
        BoondManager::INTEGRATION_NAME,
        Servicenow::INTEGRATION_NAME,
        ZohoCrm::INTEGRATION_NAME,
        ZohoDesk::INTEGRATION_NAME,
        ZohoRecruit::INTEGRATION_NAME,
        ActiveCampaign::INTEGRATION_NAME,
        InesCrm::INTEGRATION_NAME,
        Tempworks::INTEGRATION_NAME,
        AccessRecruitment::INTEGRATION_NAME,
        Zendesk::INTEGRATION_NAME
    ];

    /**
     * @param Integration $integration
     * @param array $newConfiguration
     * @param int|null $userId
     * @return int
     * @throws ContactSyncException
     */
    public function __process(
        Integration $integration,
        array       $newConfiguration,
        ?int        $userId
    ): int {
        $this->integration = $integration;
        $processedConfiguration = $this->process($newConfiguration, $userId);
        $finalConfiguration = $this->postProcess($processedConfiguration, $userId);
        return $this->integrationRepository->saveIntegrationConfiguration(
            $integration->getId(),
            $finalConfiguration
        );
    }

    /**
     * @param array $newConfiguration
     * @param int|null $userId
     * @return array
     */
    abstract public function process(array $newConfiguration, ?int $userId): array;

    /**
     * Generation d'un UUID (v4 selon la rfc)
     *
     * @return string Retourne un UUID aléatoire au format xxxxxxxx-xxxx-4xxx-Yxxx-xxxxxxxxxxxx
     */
    public function randomUuid()
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $id       = str_split(bin2hex($bytes), 4);

        return "{$id[0]}{$id[1]}-{$id[2]}-{$id[3]}-{$id[4]}-{$id[5]}{$id[6]}{$id[7]}";
    }

    /**
     * @throws ContactSyncException
     */
    private function postProcess(array $processedConfiguration, ?int $userId): array
    {
        $processedConfiguration = $this->convertBoolVal($processedConfiguration);
        $processedConfiguration = $this->preserveSavedConf($processedConfiguration);
        return $this->manageContactSync($processedConfiguration, $userId);
    }

    /**
     * Convert to bool val. For ex. "0" => false, "1" => true
     * @param array $processedConfiguration
     * @return array
     */
    private function convertBoolVal(array $processedConfiguration): array
    {
        foreach (self::CONF_BOOL_VAL as $key) {
            if (isset($processedConfiguration[$key]) && !is_bool($processedConfiguration[$key])) {
                $processedConfiguration[$key] = boolval($processedConfiguration[$key]);
            }
        }
        return $processedConfiguration;
    }

    /**
     * Preserve saved value which are present in DB, but not exist and send from dashboard
     * @param array $processedConfiguration
     * @return array
     */
    private function preserveSavedConf(array $processedConfiguration): array
    {
        foreach (self::CONF_TO_PRESERVE as $key) {
            if (
                !is_array($key) &&
                isset($this->integration->getConfiguration()[$key]) &&
                !isset($processedConfiguration[$key])
            ) {
                $processedConfiguration[$key] = $this->integration->getConfiguration()[$key];
            } elseif (is_array($key)) {
                $savedValue = $this->integration->getConfiguration();
                $valueFounded = true;
                foreach ($key as $partKey) {
                    if (!isset($savedValue[$partKey])) {
                        $valueFounded = false;
                        break;
                    }
                    $savedValue = $savedValue[$partKey];
                }

                if (!$valueFounded) {
                    continue;
                }

                $valuePointer =& $processedConfiguration;
                foreach ($key as $partKey) {
                    if (!isset($valuePointer[$partKey])) {
                        $valuePointer[$partKey] = [];
                    }

                    $valuePointer =& $valuePointer[$partKey];
                }

                $valuePointer = $savedValue;
            }
        }

        return $processedConfiguration;
    }

    /**
     * @throws ContactSyncException
     */
    private function manageContactSync(array $processedConfiguration, ?int $userId): array
    {
        foreach (self::CONF_SYNC_CONTACT as $integrationName) {
            if ($integrationName === $this->integration->getIntegrationName()) {
                if (isset($processedConfiguration['syncContactEnabledV4'])) {
                    $processedConfiguration = $this->configPullSyncContact($processedConfiguration, $userId);
                }
                if (isset($processedConfiguration['onUsageSyncEnabled'])) {
                    $processedConfiguration = $this->configOnUsageSyncContact($processedConfiguration, $userId);
                }
            }
        }
        return $processedConfiguration;
    }
}
