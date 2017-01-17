<?php

namespace ContinuousPipe\Builder\Archive;

use ContinuousPipe\Builder\Archive;
use Docker\Context\Context;
use Symfony\Component\Filesystem\Filesystem;

class FileSystemArchive extends Context implements Archive
{
    private $fileSystemStream;
    private $fileSystemProcess;

    public static function fromStream($resource)
    {
        $directory = tempnam(sys_get_temp_dir(), 'fs-from-stream');
        if (file_exists($directory)) {
            unlink($directory);
        }

        $archive = new self($directory);
        $archive->writeStream('/', $resource);

        return $archive;
    }

    /**
     * Delete the archive.
     */
    public function delete()
    {
        $fileSystem = new Filesystem();
        $fileSystem->remove($this->getDirectory());
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, Archive $archive)
    {
        $this->writeStream($path, $archive->read());
    }

    /**
     * @param string $path
     * @param resource $stream
     *
     * @throws ArchiveException
     */
    private function writeStream(string $path, $stream)
    {
        $pipesDescription = [
            ['pipe', 'r'], // stdin
            ['pipe', 'w'], // stdout
            ['pipe', 'w'], // stderr
        ];

        $targetPath = $this->getDirectory().$path;

        // TODO Ensure it's still in the `directory`
        if (!file_exists($targetPath)) {
            mkdir($targetPath, 0777, true);
        }

        $this->fileSystemProcess = proc_open('/usr/bin/env tar x --strip-components=1', $pipesDescription, $pipes, $targetPath);
        if (!is_resource($this->fileSystemProcess)) {
            throw new ArchiveException('Unable to open a stream to write the artifact');
        }

        try {
            while (!feof($stream)) {
                if (false === fwrite($pipes[0], fread($stream, 4096))) {
                    throw new ArchiveException('Unable to copy the artifact stream into the archive');
                }
            }

            if (false === fclose($stream) || false == fclose($pipes[0])) {
                throw new ArchiveException('Unable to close the artifact to archive stream');
            }

            $error = stream_get_contents($pipes[2]);

            if (!empty($error)) {
                throw new ArchiveException('Something went wrong while un-taring the stream: '.$error);
            }
        } finally {
            @fclose($stream);
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);

            proc_close($this->fileSystemProcess);
        }
    }

    public function __destruct()
    {
        parent::__destruct();

        if (is_resource($this->fileSystemProcess)) {
            proc_close($this->fileSystemProcess);
        }

        if (is_resource($this->fileSystemStream)) {
            fclose($this->fileSystemStream);
        }
    }
}
