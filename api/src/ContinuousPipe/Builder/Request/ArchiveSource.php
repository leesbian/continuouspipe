<?php

namespace ContinuousPipe\Builder\Request;

class ArchiveSource
{
    /**
     * @var string
     */
    private $url;

    /**
     * @var array
     */
    private $headers;

    /**
     * @param string $url
     * @param array  $headers
     */
    public function __construct($url, array $headers = [])
    {
        $this->url = $url;
        $this->headers = $headers;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers ?: [];
    }
}
