<?php

namespace spec\ContinuousPipe\River;

use ContinuousPipe\River\CodeRepository;
use PhpSpec\ObjectBehavior;

class CodeReferenceSpec extends ObjectBehavior
{
    public function let(CodeRepository $codeRepository)
    {
        $this->beConstructedWith($codeRepository, 'aa72dc9822c196fb8bfe03f771fb318462f13b9b', 'master');
    }

    public function it_exposes_the_sha_of_the_commit()
    {
        $this->getCommitSha()->shouldReturn('aa72dc9822c196fb8bfe03f771fb318462f13b9b');
    }

    public function it_exposes_the_branch()
    {
        $this->getBranch()->shouldReturn('master');
    }

    public function it_exposes_the_code_repository(CodeRepository $codeRepository)
    {
        $this->getRepository()->shouldReturn($codeRepository);
    }
}
