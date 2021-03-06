<?php

namespace ContinuousPipe\River\Infrastructure\Doctrine\Flow\Projections;

use ContinuousPipe\River\CodeRepository;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use ContinuousPipe\River\Flow\Projections\FlatFlowRepository;
use ContinuousPipe\River\Repository\FlowNotFound;
use ContinuousPipe\Security\Team\Team;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Ramsey\Uuid\UuidInterface;

class DoctrineFlatFlowProjectionRepository implements FlatFlowRepository
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * {@inheritdoc}
     */
    public function find(UuidInterface $uuid)
    {
        $query = $this->getRepository()->createQueryBuilder('f')
            ->select('f, p')
            ->leftJoin('f.pipelines', 'p')
            ->where('f.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            // For whatever reason, this is required
            ->setHint(Query::HINT_REFRESH, true)
        ;

        if (null === ($flow = $query->getOneOrNullResult())) {
            throw new FlowNotFound(sprintf('Flow "%s" not found', (string) $uuid));
        }

        return $flow;
    }

    /**
     * {@inheritdoc}
     */
    public function findByTeam(Team $team) : array
    {
        return $this->getRepository()->findBy([
            'team' => $team->getSlug(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function findByCodeRepository(CodeRepository $repository) : array
    {
        return $this->getRepository()->findBy([
            'repository' => $repository->getIdentifier(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(UuidInterface $uuid)
    {
        $flow = $this->find($uuid);

        $this->entityManager->remove($flow);
        $this->entityManager->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function save(FlatFlow $flow)
    {
        $pipelines = $flow->getPipelines();

        $repository = $this->entityManager->merge($flow->getRepository());
        $flow = $this->entityManager->merge($flow);

        $this->entityManager->persist($repository);
        $this->entityManager->persist($flow);

        foreach ($pipelines as $key => $pipeline) {
            $pipeline->setFlow($flow);

            $pipeline = $this->entityManager->merge($pipeline);
            $this->entityManager->persist($pipeline);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function getRepository()
    {
        return $this->entityManager->getRepository(FlatFlow::class);
    }
}
