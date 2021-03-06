<?php

namespace AppBundle\Controller;

use AppBundle\Request\WatchRequest;
use ContinuousPipe\River\Managed\Resources\Calculation\ResourceCalculator;
use ContinuousPipe\River\Environment\DeployedEnvironment;
use ContinuousPipe\River\Environment\DeployedEnvironmentRepository;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use ContinuousPipe\River\Managed\Resources\ResourceUsageResolver;
use ContinuousPipe\Security\Credentials\BucketRepository;
use ContinuousPipe\Security\Credentials\Cluster;
use ContinuousPipe\Security\User\User;
use ContinuousPipe\Watcher\Watcher;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use FOS\RestBundle\Controller\Annotations\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use ContinuousPipe\Watcher\WatcherException;

/**
 * @Route(service="app.controller.flow_environment")
 * @ParamConverter("flow", converter="flow", options={"identifier"="uuid", "flat"=true})
 */
class FlowEnvironmentController
{
    /**
     * @var DeployedEnvironmentRepository
     */
    private $environmentClient;

    /**
     * @var BucketRepository
     */
    private $bucketRepository;

    /**
     * @var Watcher
     */
    private $watcher;

    /**
     * @param DeployedEnvironmentRepository $environmentClient
     * @param BucketRepository              $bucketRepository
     * @param Watcher                       $watcher
     */
    public function __construct(
        DeployedEnvironmentRepository $environmentClient,
        BucketRepository $bucketRepository,
        Watcher $watcher
    ) {
        $this->environmentClient = $environmentClient;
        $this->watcher = $watcher;
        $this->bucketRepository = $bucketRepository;
    }

    /**
     * @Route("/flows/{uuid}/environments", methods={"GET"})
     * @Security("is_granted('READ', flow)")
     * @View
     */
    public function listAction(FlatFlow $flow)
    {
        return $this->environmentClient->findByFlow($flow);
    }

    /**
     * @Route("/flows/{uuid}/environments/{name}", methods={"DELETE"})
     * @ParamConverter("user", converter="user")
     * @Security("is_granted('DELETE', flow)")
     * @View
     */
    public function deleteAction(FlatFlow $flow, Request $request, User $user, $name)
    {
        $environment = new DeployedEnvironment($name, $request->query->get('cluster'));

        $this->environmentClient->delete($flow->getTeam(), $user, $environment);
    }

    /**
     * @Route("/flows/{uuid}/clusters/{clusterIdentifier}/namespaces/{namespace}/pods/{podName}", methods={"DELETE"})
     * @Security("is_granted('DELETE', flow)")
     * @View
     */
    public function deletePodAction(FlatFlow $flow, $clusterIdentifier, $namespace, $podName)
    {
        $this->environmentClient->deletePod($flow, $clusterIdentifier, $namespace, $podName);
    }

    /**
     * @Route("/flows/{uuid}/environments/watch", methods={"POST"})
     * @ParamConverter("watchRequest", converter="fos_rest.request_body")
     * @Security("is_granted('READ', flow)")
     * @View
     */
    public function watchAction(FlatFlow $flow, WatchRequest $watchRequest)
    {
        $bucket = $this->bucketRepository->find($flow->getTeam()->getBucketUuid());
        $clusters = $bucket->getClusters()->filter(function (Cluster $cluster) use ($watchRequest) {
            return $cluster->getIdentifier() == $watchRequest->getCluster();
        });

        if ($clusters->count() != 1) {
            throw new BadRequestHttpException(sprintf('Expected one cluster found %d', $clusters->count()));
        }

        try {
            return $this->watcher->logs(
                $clusters->first(),
                $watchRequest->getEnvironment(),
                $watchRequest->getPod()
            );
        } catch (WatcherException $e) {
            return new JsonResponse([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }
}
