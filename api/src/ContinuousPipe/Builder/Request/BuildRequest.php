<?php

namespace ContinuousPipe\Builder\Request;

use ContinuousPipe\Builder\Context;
use ContinuousPipe\Builder\Image;
use ContinuousPipe\Builder\Logging;
use ContinuousPipe\Builder\Notification;
use ContinuousPipe\Builder\Repository;
use Ramsey\Uuid\Uuid;

class BuildRequest
{
    /**
     * @var Repository
     */
    private $repository;

    /**
     * @var Archive
     */
    private $archive;

    /**
     * @var Image
     */
    private $image;

    /**
     * @var Notification
     */
    private $notification;

    /**
     * @var Logging
     */
    private $logging;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var Uuid
     */
    private $credentialsBucket;

    /**
     * @var array
     */
    private $environment;

    /**
     * @param Repository|Archive $repositoryOrArchive
     * @param Image              $image
     * @param Context            $context
     * @param Notification       $notification
     * @param Logging            $logging
     * @param array              $environment
     */
    public function __construct($repositoryOrArchive, Image $image, Context $context = null, Notification $notification = null, Logging $logging = null, array $environment = [])
    {
        if ($repositoryOrArchive instanceof Repository) {
            $this->repository = $repositoryOrArchive;
        } elseif ($repositoryOrArchive instanceof Archive) {
            $this->archive = $repositoryOrArchive;
        } else {
            throw new \InvalidArgumentException('Expected a repository or an archive');
        }

        $this->image = $image;
        $this->notification = $notification;
        $this->logging = $logging;
        $this->context = $context;
        $this->environment = $environment;
    }

    /**
     * @return Repository
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * @return Archive
     */
    public function getArchive()
    {
        return $this->archive;
    }

    /**
     * @return Image
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * @return Notification
     */
    public function getNotification()
    {
        return $this->notification;
    }

    /**
     * @return Logging
     */
    public function getLogging()
    {
        return $this->logging;
    }

    /**
     * @return Context
     */
    public function getContext()
    {
        return $this->context ?: new Context('', '');
    }

    /**
     * @return array
     */
    public function getEnvironment()
    {
        return $this->environment ?: [];
    }

    /**
     * @return Uuid
     */
    public function getCredentialsBucket()
    {
        if (is_string($this->credentialsBucket)) {
            $this->credentialsBucket = Uuid::fromString($this->credentialsBucket);
        }

        return $this->credentialsBucket;
    }
}
