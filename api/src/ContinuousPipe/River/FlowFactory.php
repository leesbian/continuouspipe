<?php

namespace ContinuousPipe\River;

use ContinuousPipe\Events\Transaction\TransactionManager;
use ContinuousPipe\River\Flow\ConfigurationException;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use ContinuousPipe\River\Flow\Projections\FlatFlowRepository;
use ContinuousPipe\River\Flow\Request\FlowCreationRequest;
use ContinuousPipe\River\Flow\Request\FlowUpdateRequest;
use ContinuousPipe\Security\User\UserContext;
use ContinuousPipe\Security\Team\Team;
use Ramsey\Uuid\Uuid;
use SimpleBus\Message\Bus\MessageBus;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class FlowFactory
{
    /**
     * @var UserContext
     */
    private $userContext;

    /**
     * @var MessageBus
     */
    private $eventBus;

    /**
     * @var FlatFlowRepository
     */
    private $flatFlowRepository;

    /**
     * @var TransactionManager
     */
    private $flowTransactionManager;

    public function __construct(
        UserContext $userContext,
        MessageBus $eventBus,
        FlatFlowRepository $flatFlowRepository,
        TransactionManager $flowTransactionManager
    ) {
        $this->userContext = $userContext;
        $this->eventBus = $eventBus;
        $this->flatFlowRepository = $flatFlowRepository;
        $this->flowTransactionManager = $flowTransactionManager;
    }

    /**
     * @param FlowCreationRequest $creationRequest
     *
     * @return FlatFlow
     */
    public function fromCreationRequest(Team $team, FlowCreationRequest $creationRequest)
    {
        if (null != $creationRequest->getUuid()) {
            $uuid = Uuid::fromString($creationRequest->getUuid());
        } else {
            $uuid = Uuid::uuid1();
        }

        $flow = Flow::create(
            $uuid,
            $team,
            $this->userContext->getCurrent(),
            $creationRequest->getRepository()
        );

        foreach ($flow->raisedEvents() as $event) {
            $this->eventBus->handle($event);
        }

        return $this->flatFlowRepository->find($uuid);
    }

    /**
     * @param Flow              $flow
     * @param FlowUpdateRequest $updateRequest
     *
     * @throws ConfigurationException
     *
     * @return FlatFlow
     */
    public function update(Flow $flow, FlowUpdateRequest $updateRequest)
    {
        $this->flowTransactionManager->apply($flow->getUuid(), function (Flow $flow) use ($updateRequest) {
            $flow->update(
                $this->parseConfiguration($updateRequest)
            );
        });

        return $this->flatFlowRepository->find($flow->getUuid());
    }

    /**
     * @param FlowUpdateRequest $updateRequest
     *
     * @throws ConfigurationException
     *
     * @return array
     */
    private function parseConfiguration(FlowUpdateRequest $updateRequest)
    {
        $configuration = $updateRequest->getYmlConfiguration();
        if (empty($configuration)) {
            return [];
        }

        try {
            return Yaml::parse($configuration);
        } catch (ParseException $e) {
            throw new ConfigurationException('The configuration is not a valid YAML file: '.$e->getMessage(), $e->getCode(), $e);
        }
    }
}
