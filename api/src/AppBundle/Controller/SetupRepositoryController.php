<?php

namespace AppBundle\Controller;

use AppBundle\Repository\UserRepositoryRepository;
use GitHub\WebHook\Setup\WebHookManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use FOS\RestBundle\Controller\Annotations\View;

/**
 * @Route(service="app.controller.setup_repository")
 */
class SetupRepositoryController
{
    /**
     * @var WebHookManager
     */
    private $webHookManager;
    /**
     * @var UserRepositoryRepository
     */
    private $userRepositoryRepository;

    /**
     * @param WebHookManager $webHookManager
     */
    public function __construct(WebHookManager $webHookManager, UserRepositoryRepository $userRepositoryRepository)
    {
        $this->webHookManager = $webHookManager;
        $this->userRepositoryRepository = $userRepositoryRepository;
    }

    /**
     * @Route("/user-repositories/{id}/activate", methods={"POST"})
     * @View
     */
    public function setupAction($id)
    {
        $repository = $this->userRepositoryRepository->findById($id);
        $webHook = $this->webHookManager->setup($repository);

        return $webHook;
    }
}
