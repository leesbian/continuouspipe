<?php

namespace ContinuousPipe\River\Task\Delete;

use ContinuousPipe\River\Environment\DeployedEnvironment;
use ContinuousPipe\River\Environment\DeployedEnvironmentException;
use ContinuousPipe\River\Environment\DeployedEnvironmentRepository;
use ContinuousPipe\River\Event\TideEvent;
use ContinuousPipe\River\EventBased\ApplyEventCapability;
use ContinuousPipe\River\EventCollection;
use ContinuousPipe\River\Flow\Projections\FlatFlowRepository;
use ContinuousPipe\River\Pipe\DeploymentRequest\Cluster\ClusterResolutionException;
use ContinuousPipe\River\Pipe\DeploymentRequest\Cluster\TargetClusterResolver;
use ContinuousPipe\River\Task\Delete\Event\EnvironmentDeleted;
use ContinuousPipe\River\Task\Delete\Event\EnvironmentDeletionFailed;
use ContinuousPipe\River\Task\Delete\Event\StartedEnvironmentDeletion;
use ContinuousPipe\River\Pipe\DeploymentRequest\EnvironmentName\EnvironmentNamingStrategy;
use ContinuousPipe\River\Task\Deploy\Naming\UnresolvedEnvironmentNameException;
use ContinuousPipe\River\Task\Task;
use ContinuousPipe\River\Task\TaskCreated;
use ContinuousPipe\River\Task\TaskEvent;
use ContinuousPipe\River\Tide;
use ContinuousPipe\River\Tide\Configuration\ArrayObject;
use LogStream\Log;
use LogStream\LoggerFactory;
use LogStream\Node\Text;

class DeleteTask implements Task
{
    use ApplyEventCapability {
        apply as doApply;
    }

    private $tideUuid;
    private $identifier;
    private $logIdentifier;
    private $label = 'Deleting environment';
    private $status = Task::STATUS_PENDING;
    private $configuration;

    /**
     * @var EventCollection
     */
    private $eventCollection;

    public function __construct(EventCollection $eventCollection, array $events)
    {
        $this->eventCollection = $eventCollection;

        foreach ($events as $event) {
            $this->apply($event);
        }
    }

    public function start(Tide $tide, LoggerFactory $loggerFactory, DeployedEnvironmentRepository $deployedEnvironmentRepository, EnvironmentNamingStrategy $environmentNamingStrategy, TargetClusterResolver $targetClusterResolver)
    {
        if ($this->status == Task::STATUS_RUNNING) {
            throw new \RuntimeException('The task is already running');
        }

        $label = sprintf('Deleting environment (%s)', $this->getIdentifier());
        $logger = $loggerFactory->from($tide->getLog())->child(new Text($label))->updateStatus(Log::RUNNING);

        $configuration = new DeleteTaskConfiguration(
            $this->configuration['cluster'],
            $this->configuration['environment']['name']
        );

        try {
            try {
                $cluster = $targetClusterResolver->getClusterIdentifier($tide, $configuration);
            } catch (ClusterResolutionException $e) {
                throw new DeployedEnvironmentException($e->getMessage(), $e->getCode(), $e);
            }

            try {
                $deployedEnvironment = new DeployedEnvironment(
                    $environmentNamingStrategy->getName(
                        $tide,
                        $cluster,
                        $configuration->getEnvironmentName()
                    ),
                    $cluster->getIdentifier()
                );
            } catch (UnresolvedEnvironmentNameException $e) {
                throw new DeployedEnvironmentException('Can\'t find the environment name: '.$e->getMessage(), $e->getCode(), $e);
            }

            $childLogger = $logger->child(new Text(sprintf(
                'Deleting environment <code>%s</code>',
                $deployedEnvironment->getIdentifier()
            )));

            $deployedEnvironmentRepository->delete(
                $tide->getTeam(),
                $tide->getUser(),
                $deployedEnvironment
            );

            $this->eventCollection->raiseAndApply(new EnvironmentDeleted(
                $this->tideUuid,
                $this->identifier,
                $logger->getLog()->getId(),
                $label
            ));

            $childLogger->updateStatus(Log::SUCCESS);
            $logger->updateStatus(Log::SUCCESS);
        } catch (DeployedEnvironmentException $e) {
            $this->eventCollection->raiseAndApply(new EnvironmentDeletionFailed(
                $this->tideUuid,
                $this->identifier,
                $logger->getLog()->getId(),
                $label,
                $e->getMessage()
            ));

            if (isset($childLogger)) {
                $childLogger->child(new Text($e->getMessage()));
                $childLogger->updateStatus(Log::FAILURE);
            } else {
                $logger->child(new Text($e->getMessage()))->updateStatus(Log::FAILURE);
            }

            $logger->updateStatus(Log::FAILURE);
        }
    }

    public function applyTaskCreated(TaskCreated $event)
    {
        $this->tideUuid = $event->getTideUuid();
        $this->identifier = $event->getTaskId();
        $this->configuration = $event->getConfiguration();
    }

    public function applyEnvironmentDeleted(EnvironmentDeleted $event)
    {
        $this->status = self::STATUS_SUCCESSFUL;
    }

    public function applyEnvironmentDeletionFailed(EnvironmentDeletionFailed $event)
    {
        $this->status = self::STATUS_FAILED;
    }

    /**
     * {@inheritdoc}
     */
    public function accept(TideEvent $event)
    {
        return $event instanceof TaskEvent && $event->getTaskId() == $this->getIdentifier();
    }

    /**
     * {@inheritdoc}
     */
    public function apply(TideEvent $event)
    {
        $this->doApply($event);
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogIdentifier(): string
    {
        return $this->logIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * {@inheritdoc}
     */
    public function getExposedContext()
    {
        return new ArrayObject([]);
    }
}
