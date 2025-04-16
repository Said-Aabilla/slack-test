<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\ExternalUserMapping\DTO\ExternalUserDTO;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Intrastructure\Persistence\UserRepository;

abstract class AbstractExternalUserMapping extends AbstractProcess
{

    protected UserRepository $userRepository;

    public function __construct(
        IntegrationRepository      $integrationRepository,
        IntegrationLoggerInterface $logger,
        UserRepository             $userRepository
    )
    {
        parent::__construct($integrationRepository, $logger);
        $this->userRepository = $userRepository;
    }


    /**
     * Get an external user list from integration service
     * @return ExternalUserDTO[]
     */
    abstract public function getExternalUserList(): array;

    /**
     * @return array
     */
    public function getExternalUserConfiguration(): array
    {
        $externalUserList = $this->getExternalUserList();
        $ringoverUserList = $this->userRepository->getUsersByTeamId($this->integration->getTeamId());
        return [
            'automatic' => $this->getAutomaticExternalUserList($externalUserList, $ringoverUserList),
            'manuel' => $this->getManuelExternalUserList($externalUserList, $ringoverUserList),
            'manuel_input' => false
        ];
    }

    /**
     * @param ExternalUserDTO[] $externalUserList
     * @param array $ringoverUserList
     * @return void
     */
    protected function getAutomaticExternalUserList(
        array $externalUserList,
        array $ringoverUserList
    ): array
    {
        $disabledUserList = $this->integration->getConfiguration()['ringover_user_to_external']['disabled_users'] ??
            [];
        $ringoverUserExternalList = $this->integration->getConfiguration()['ringover_user_to_external']['users'] ?? [];
        $automaticUserConnectionList = [];
        foreach ($ringoverUserList as $ringoverUser) {
            foreach ($externalUserList as $externalUser) {
                if (
                    $externalUser->email === $ringoverUser['email']
                    && !$this->isUserManuallyMapped($externalUser, $ringoverUser['id'], $ringoverUserExternalList)
                ) {
                    $automaticUserConnectionList[] = [
                        $ringoverUser['id'] => array_merge(
                            [
                                'external_id'       => $externalUser->id,
                                'external_fullname' => $externalUser->fullName,
                                'ringover_fullname' => $ringoverUser['fullName'],
                                'email'             => $externalUser->email,
                                'avatar'            => $externalUser->avatar
                            ],
                            $this->getDisabledAutomaticUser(
                                $ringoverUser['id'],
                                $externalUser->id,
                                $disabledUserList,
                                $ringoverUserExternalList
                            )
                        )
                    ];
                    //break at the first correspondence to avoid looping all the external user list and multiple user
                    break;
                }
            }
        }
        return $automaticUserConnectionList;
    }

    /**
     * @param ExternalUserDTO[] $externalUserList
     * @param array $ringoverUserList
     * @return array
     */
    protected function getManuelExternalUserList(array $externalUserList, array $ringoverUserList): array
    {
        $ringoverUserExternalList = $this->integration->getConfiguration()['ringover_user_to_external']['users'] ?? [];
        if (empty($ringoverUserExternalList)) {
            return [];
        }
        $manuelUserExternalList = [];
        foreach ($ringoverUserList as $ringoverUser) {
            if (isset($ringoverUserExternalList[$ringoverUser['id']])) {
                $manuelUserExternal = $ringoverUserExternalList[$ringoverUser['id']];
                foreach ($externalUserList as $externalUser) {
                    if ($this->isManuallyMappedUserValid($externalUser, $manuelUserExternal)) {
                        $manuelUserExternalList[] = [
                            $ringoverUser['id'] => [
                                'external_id' => $externalUser->id,
                                'external_fullname' => $externalUser->fullName,
                                'ringover_fullname' => $ringoverUser['fullName'],
                                'email' => $externalUser->email,
                                'avatar' => $externalUser->avatar
                            ]
                        ];
                    }
                }
            }
        }
        return $manuelUserExternalList;
    }

    private function isUserManuallyMapped(
        ExternalUserDTO $externalUser,
        $ringoverUserId,
        array $ringoverUserExternalList
    ): bool {
        $manuallyMappedUser = $ringoverUserExternalList[$ringoverUserId] ?? null;
        if (is_null($manuallyMappedUser)) {
            return false;
        }
        return $this->isManuallyMappedUserValid($externalUser, $manuallyMappedUser);
    }

    /**
     * Vérifier la validité de l'user mappé manullement, sur son ID ou Email
     *
     * @param ExternalUserDTO $externalUser
     * @param $manuelUserExternal
     * @return bool
     */
    protected function isManuallyMappedUserValid(ExternalUserDTO $externalUser, $manuelUserExternal): bool
    {
        // User mappé est un string. Check ID ou Email
        $isStrValValid = is_string($manuelUserExternal) &&
        ($manuelUserExternal === $externalUser->email || $manuelUserExternal == $externalUser->id);

        // User mappé est un tableau. Check ID
        $isArrayValValid = is_array($manuelUserExternal) &&
            $externalUser->id == $manuelUserExternal['externalId'] && ($manuelUserExternal['enabled'] ?? true);

        return $isStrValValid || $isArrayValValid;
    }

    /**
     * Get user mapping is disabled add date if exist
     * @param int $ringoverUserId
     * @param $externalUserId
     * @param $disabledUserList
     * @param $ringoverUserExternalList
     * @return array
     */
    private function getDisabledAutomaticUser(
        int $ringoverUserId,
            $externalUserId,
            $disabledUserList,
            $ringoverUserExternalList
    ): array
    {
        if (
            isset($disabledUserList[$ringoverUserId]) &&
            $disabledUserList[$ringoverUserId]['externalId'] === $externalUserId
        ) {
            return [
                'date_disabled' => $disabledUserList[$ringoverUserId]['disabledDate'],
                'disabled' => true
            ];
        }
        if (
            isset($ringoverUserExternalList[$ringoverUserId]) &&
            $ringoverUserExternalList[$ringoverUserId]['externalId'] === $externalUserId &&
            !($ringoverUserExternalList[$ringoverUserId]['enabled'] ?? true)
        ) {
            return [
                'date_disabled' => null,
                'disabled' => true
            ];
        }
        return [
            'date_disabled' => null,
            'disabled' => false
        ];
    }

}
