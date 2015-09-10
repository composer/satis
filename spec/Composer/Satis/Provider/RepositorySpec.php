<?php

namespace spec\Composer\Satis\Provider;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class RepositorySpec extends ObjectBehavior
{
    function let()
    {
        $this->beConstructedWith('name', 'url');
    }

    function it_sets_properties_form_constructor()
    {
        $this->getName()->shouldBe('name');
        $this->getUrl()->shouldBe('url');
    }
}
