<?php

namespace ContinuousPipe\Adapter\Kubernetes\Handler;

use ContinuousPipe\Adapter\Kubernetes\Client\DeploymentClientFactory;
use ContinuousPipe\Adapter\Kubernetes\Component\ComponentCreationStatus;
use ContinuousPipe\Adapter\Kubernetes\Event\PublicServicesCreated;
use ContinuousPipe\Adapter\Kubernetes\ObjectDeployer\ObjectDeployer;
use ContinuousPipe\Adapter\Kubernetes\PublicEndpoint\PublicEndpointObjectVoter;
use ContinuousPipe\Adapter\Kubernetes\Transformer\EnvironmentTransformer;
use ContinuousPipe\Model\Environment;
use ContinuousPipe\Pipe\Command\CreatePublicEndpointsCommand;
use ContinuousPipe\Pipe\DeploymentContext;
use ContinuousPipe\Pipe\Event\DeploymentFailed;
use ContinuousPipe\Pipe\Handler\Deployment\DeploymentHandler;
use ContinuousPipe\Security\Credentials\Cluster\Kubernetes;
use Kubernetes\Client\Model\KubernetesObject;
use Kubernetes\Client\NamespaceClient;
use LogStream\Log;
use LogStream\LoggerFactory;
use LogStream\Node\Text;
use SimpleBus\Message\Bus\MessageBus;

class CreatePublicEndpointsHandler implements DeploymentHandler
{
    /**
     * @var EnvironmentTransformer
     */
    private $environmentTransformer;

    /**
     * @var DeploymentClientFactory
     */
    private $clientFactory;

    /**
     * @var MessageBus
     */
    private $eventBus;

    /**
     * @var LoggerFactory
     */
    private $loggerFactory;

    /**
     * @var PublicEndpointObjectVoter
     */
    private $publicServiceVoter;
    /**
     * @var ObjectDeployer
     */
    private $objectDeployer;

    /**
     * @param EnvironmentTransformer    $environmentTransformer
     * @param DeploymentClientFactory   $clientFactory
     * @param MessageBus                $eventBus
     * @param LoggerFactory             $loggerFactory
     * @param PublicEndpointObjectVoter $publicServiceVoter
     * @param ObjectDeployer            $objectDeployer
     */
    public function __construct(
        EnvironmentTransformer $environmentTransformer,
        DeploymentClientFactory $clientFactory,
        MessageBus $eventBus,
        LoggerFactory $loggerFactory,
        PublicEndpointObjectVoter $publicServiceVoter,
        ObjectDeployer $objectDeployer
    ) {
        $this->environmentTransformer = $environmentTransformer;
        $this->clientFactory = $clientFactory;
        $this->eventBus = $eventBus;
        $this->loggerFactory = $loggerFactory;
        $this->publicServiceVoter = $publicServiceVoter;
        $this->objectDeployer = $objectDeployer;
    }

    /**
     * @param CreatePublicEndpointsCommand $command
     */
    public function handle(CreatePublicEndpointsCommand $command)
    {
        $context = $command->getContext();

        $logger = $this->loggerFactory->from($context->getLog())->child(new Text('Create services for public endpoints'));
        $logger->updateStatus(Log::RUNNING);

        try {
            $objects = $this->getPublicEndpointObjects($context->getEnvironment());
            $status = $this->createPublicEndpointObjects($this->clientFactory->get($context), $objects);

            $logger->updateStatus(Log::SUCCESS);
            $this->eventBus->handle(new PublicServicesCreated($context, $status));
        } catch (\Exception $e) {
            $logger->child(new Text($e->getMessage()));
            $logger->updateStatus(Log::FAILURE);

            $this->eventBus->handle(new DeploymentFailed($context));
        }
    }

    /**
     * @param NamespaceClient    $namespaceClient
     * @param KubernetesObject[] $objects
     *
     * @return ComponentCreationStatus
     */
    private function createPublicEndpointObjects(NamespaceClient $namespaceClient, array $objects)
    {
        $status = new ComponentCreationStatus();

        foreach ($objects as $object) {
            $status->merge(
                $this->objectDeployer->deploy($namespaceClient, $object)
            );
        }

        return $status;
    }

    /**
     * @param Environment $environment
     *
     * @return KubernetesObject[]
     */
    private function getPublicEndpointObjects(Environment $environment)
    {
        $namespaceObjects = $this->environmentTransformer->getElementListFromEnvironment($environment);
        $objects = array_filter($namespaceObjects, function (KubernetesObject $object) {
            return $this->publicServiceVoter->isPublicEndpointObject($object);
        });

        return array_values($objects);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(DeploymentContext $context)
    {
        return $context->getCluster() instanceof Kubernetes;
    }
}
