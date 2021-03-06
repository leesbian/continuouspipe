<?php

namespace ContinuousPipe\River\Task\Deploy\Listener\Logging;

use ContinuousPipe\River\Task\Deploy\Event\DeploymentEvent;
use ContinuousPipe\River\Task\Deploy\Event\DeploymentFailed;
use ContinuousPipe\River\Task\Deploy\Event\DeploymentSuccessful;
use LogStream\Log;
use LogStream\LoggerFactory;

class DeploymentListener
{
    /**
     * @var LoggerFactory
     */
    private $loggerFactory;

    /**
     * @param LoggerFactory $loggerFactory
     */
    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->loggerFactory = $loggerFactory;
    }

    /**
     * @param DeploymentEvent $event
     */
    public function notify(DeploymentEvent $event)
    {
        if ($event instanceof DeploymentFailed) {
            $this->getLogger($event)->updateStatus(Log::FAILURE);
        } elseif ($event instanceof DeploymentSuccessful) {
            $this->getLogger($event)->updateStatus(Log::SUCCESS);
        }
    }

    /**
     * @param DeploymentEvent $event
     *
     * @return \LogStream\Logger
     */
    private function getLogger(DeploymentEvent $event)
    {
        $parentLogId = $event->getDeployment()->getRequest()->getNotification()->getLogStreamParentId();

        return $this->loggerFactory->fromId($parentLogId);
    }
}
