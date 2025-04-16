<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\AliasMapper;
use App\Intrastructure\Persistence\CommandQueryPDO;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpNotFoundException;

class LegacyIntegrationConfigAction extends Action
{
    /**
     * @var \DI\Container
     */
    private Container $container;
    private AliasMapper $fakeIntegrationNameMapper;

    /**
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function createLegacyContainer(): array
    {
        // Compatibilité avec l'existant
        return [
            'settings' => $this->container->get('settings'),
            'database' => $this->container->get(CommandQueryPDO::class),
            'logger'   => $this->container->get(IntegrationLoggerInterface::class)
        ];
    }

    public function __construct(
        AliasMapper                $fakeIntegrationNameMapper,
        IntegrationLoggerInterface $logger,
        Container                  $container
    ) {
        parent::__construct($logger);
        $this->container = $container;
        $this->fakeIntegrationNameMapper = $fakeIntegrationNameMapper;
    }

    /**
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \DI\NotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \DI\DependencyException
     */
    protected function action(): Response
    {
        $containerDI = $this->container;
        $logger = $this->container->get(IntegrationLoggerInterface::class);
        $request = $this->request;

        // Compatibilité avec l'existant
        $container = $this->createLegacyContainer();
        $_COMMAND = createLegacyCommandRoute($this->request, intval($container['settings']['internals']['offset']));

        $GLOBALS['logger'] = $logger;
        $GLOBALS['region'] = $container['settings']['internals']['region'] ?? 'EU';
        $GLOBALS['container'] = $container;
        $GLOBALS['containerDI'] = $containerDI;
        $GLOBALS['pdoHandler'] = $container['database'];

        // Recherche et inclusion du fichier demandé
        $integrationAlias = strtoupper($this->request->getAttribute('integration_name'));
        $integrationName = strtolower(
            $this->fakeIntegrationNameMapper->getRealIntegrationName(
                $integrationAlias
            )
        );


        preg_match(
            "/^(?P<name>.*)(_(?P<version>v\d+))?$/U",
            $integrationName,
            $integrationNameDetails
        );

        // Transformation des noms au format snake_case vers CamelCase
        $integrationNameCamelCase = lcfirst(
            str_replace(
                ' ',
                '',
                ucwords(str_replace('_', ' ', $integrationNameDetails['name']))
            )
        );

        /*
         * Le nouveau chemin des fichiers legacy est :
         *  /src/Integrations/:IntegrationName/:Version/Legacy/:integration_file_path_version.php
         */
        $filenameToInclude = 'include_' . strtolower($integrationName) . '.php';
        $integrationsDir = dirname(__DIR__, 3) . '/Integrations';
        $integrationVersion = $integrationNameDetails['version'] ?? 'v1';
        $newFilePathToInclude = $integrationsDir . DIRECTORY_SEPARATOR .
                                ucfirst($integrationNameCamelCase) . DIRECTORY_SEPARATOR .
                                ucfirst($integrationVersion) . DIRECTORY_SEPARATOR .
                                'Legacy' . DIRECTORY_SEPARATOR .
                                $filenameToInclude;

        // Les fichiers des intégrations réparties un peu partout pour faire joli
        $oldFilePathToInclude = dirname(__DIR__, 4) . '/' . $filenameToInclude;

        if (file_exists($newFilePathToInclude)) {
            $filePathToInclude = $newFilePathToInclude;
        } elseif (file_exists($oldFilePathToInclude)) {
            $filePathToInclude = $oldFilePathToInclude;
        } else {
            return $this->response->withStatus(404);
        }

        include_once $filePathToInclude;
        return $this->response->withHeader('Content-Type', 'application/json');
    }
}
