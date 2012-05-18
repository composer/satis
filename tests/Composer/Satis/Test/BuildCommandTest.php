<?php
namespace Composer\Satis\Test;

use Composer\Satis\Command\BuildCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author Till Klampaeckel <till@php.net>
 */
class BuildCommandTest extends \PHPUnit_Framework_TestCase
{
    protected $fixtureDir;

    public function setUp()
    {
        $this->fixtureDir = dirname(dirname(dirname(__DIR__))) . '/fixtures';
    }

    /**
     *
     */
    public function testBuildCommand()
    {
        $this->markTestIncomplete("Cannot bootstrap BuildCommand.");

        $cwd = getcwd();

        chdir($this->fixtureDir);

        $cmd = new BuildCommand();

        $input = new ArgvInput(array(
            './bin/satis',
            'build',
            '--stylesheet=foo.css',
            'config.json',
            './foo',
        ));

        $cmd->run(new ArgvInput(array('./bin/satis')), new NullOutput());

        var_dump($cmd->getDefinitions());

        chdir($cwd);
    }
}