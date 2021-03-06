<?php

namespace ContinuousPipe\Pipe\Handler;

use ContinuousPipe\Pipe\EnvironmentClientFactory;
use ContinuousPipe\Model\Environment;
use ContinuousPipe\Pipe\Cluster\ClusterNotFound;
use ContinuousPipe\Pipe\Command\StartDeploymentCommand;
use ContinuousPipe\Pipe\DeploymentContext;
use ContinuousPipe\Pipe\Event\DeploymentFailed;
use ContinuousPipe\Pipe\Event\DeploymentStarted;
use ContinuousPipe\Pipe\Logging\DeploymentLoggerFactory;
use ContinuousPipe\Pipe\Uuid\UuidTransformer;
use ContinuousPipe\Security\Credentials\BucketNotFound;
use ContinuousPipe\Security\Credentials\BucketRepository;
use ContinuousPipe\Security\Credentials\Cluster;
use LogStream\Log;
use LogStream\Node\Text;
use Ramsey\Uuid\Uuid;
use SimpleBus\Message\Bus\MessageBus;

class StartDeploymentHandler
{
    /**
     * @var EnvironmentClientFactory
     */
    private $environmentClientFactory;

    /**
     * @var MessageBus
     */
    private $eventBus;

    /**
     * @var DeploymentLoggerFactory
     */
    private $loggerFactory;

    /**
     * @var BucketRepository
     */
    private $bucketRepository;

    /**
     * @param BucketRepository         $bucketRepository
     * @param EnvironmentClientFactory $environmentClientFactory
     * @param MessageBus               $eventBus
     * @param DeploymentLoggerFactory  $loggerFactory
     */
    public function __construct(BucketRepository $bucketRepository, EnvironmentClientFactory $environmentClientFactory, MessageBus $eventBus, DeploymentLoggerFactory $loggerFactory)
    {
        $this->bucketRepository = $bucketRepository;
        $this->environmentClientFactory = $environmentClientFactory;
        $this->eventBus = $eventBus;
        $this->loggerFactory = $loggerFactory;
    }

    /**
     * @param StartDeploymentCommand $command
     */
    public function handle(StartDeploymentCommand $command)
    {
        $deployment = $command->getDeployment();

        $logger = $this->loggerFactory->create($deployment);
        $logger->updateStatus(Log::RUNNING);

        $request = $deployment->getRequest();
        $target = $request->getTarget();
        $specification = $request->getSpecification();

        $environment = new Environment(
            $target->getEnvironmentName(),
            $target->getEnvironmentName(),
            $specification->getComponents(),
            null,
            $target->getEnvironmentLabels()
        );

        try {
            $cluster = $this->getCluster($request->getCredentialsBucket(), $target->getClusterIdentifier());
        } catch (ClusterNotFound $e) {
            $logger->child(new Text($e->getMessage()))->updateStatus(Log::FAILURE);

            $this->eventBus->handle(new DeploymentFailed(
                new DeploymentContext($deployment, null, $logger->getLog(), $environment)
            ));

            return;
        }

        $deploymentContext = new DeploymentContext($deployment, $cluster, $logger->getLog(), $environment);
        $this->eventBus->handle(new DeploymentStarted($deploymentContext));
    }

    /**
     * @param Uuid   $bucketUuid
     * @param string $clusterIdentifier
     *
     * @return Cluster
     *
     * @throws ClusterNotFound
     */
    private function getCluster(Uuid $bucketUuid, $clusterIdentifier)
    {
        try {
            $bucket = $this->bucketRepository->find(UuidTransformer::transform($bucketUuid));
        } catch (BucketNotFound $e) {
            throw new ClusterNotFound('The credentials bucket is not found', $e->getCode(), $e);
        }

        $matchingClusters = $bucket->getClusters()->filter(function (Cluster $cluster) use ($clusterIdentifier) {
            return $cluster->getIdentifier() == $clusterIdentifier;
        });

        if (0 === $matchingClusters->count()) {
            throw new ClusterNotFound(sprintf(
                'The cluster "%s" is not found in your project',
                $clusterIdentifier
            ));
        }

        return $matchingClusters->first();
    }
}
