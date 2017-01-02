<?php

namespace ContinuousPipe\Builder\Article;

use ContinuousPipe\Builder\Archive;
use ContinuousPipe\Builder\ArchiveBuilder;
use ContinuousPipe\Builder\Request\BuildRequest;
use LogStream\Logger;

class TraceableArchiveBuilder implements ArchiveBuilder
{
    /**
     * @var ArchiveBuilder
     */
    private $decoratedBuilder;

    /**
     * @var BuildRequest[]
     */
    private $requests;

    /**
     * @var Archive[]
     */
    private $archives;

    /**
     * @param ArchiveBuilder $decoratedBuilder
     */
    public function __construct(ArchiveBuilder $decoratedBuilder)
    {
        $this->decoratedBuilder = $decoratedBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function getArchive(BuildRequest $buildRequest, Logger $logger)
    {
        $archive = $this->decoratedBuilder->getArchive($buildRequest, $logger);

        $this->requests[] = $buildRequest;
        $this->archives[] = $archive;

        return $archive;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(BuildRequest $request)
    {
        return $this->decoratedBuilder->supports($request);
    }

    /**
     * @return BuildRequest[]
     */
    public function getRequests()
    {
        return $this->requests;
    }

    /**
     * @return Archive[]
     */
    public function getArchives()
    {
        return $this->archives;
    }
}
