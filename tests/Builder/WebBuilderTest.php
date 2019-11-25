<?php

declare(strict_types=1);

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Test\Satis;

use Composer\Package\Package;
use Composer\Satis\Builder\WebBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilderTest extends TestCase
{
    /** @var WebBuilder */
    protected $webBuilder;

    public function setUp(): void
    {
        $this->webBuilder = new WebBuilder(new NullOutput(), 'build', [], false);
    }

    public function dataGetDescSortedVersions(): array
    {
        $data = [];

        $data['test1 stable versions'] = [
            [
                new Package('vendor/name', '2.0.1.0', '2.0.1'),
                new Package('vendor/name', '2.0.0.0', '2.0'),
                new Package('vendor/name', '1.1.0.0', '1.1'),
                new Package('vendor/name', '1.0.0.0', '1.0'),
            ],
            [
                [
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                    new Package('vendor/name', '1.1.0.0', '1.1'),
                    new Package('vendor/name', '2.0.1.0', '2.0.1'),
                ],
            ],
        ];

        return $data;
    }

    public function testTwigEnvironment()
    {
        $twig = new Environment(new ArrayLoader([]));
        $this->webBuilder->setTwigEnvironment($twig);

        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigEnvironment');
        $method->setAccessible(true);

        $this->assertSame($twig, $method->invoke($this->webBuilder));
    }

    public function testTwigEnvironmentDefault()
    {
        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigEnvironment');
        $method->setAccessible(true);

        $this->assertInstanceOf('\Twig\Environment', $method->invoke($this->webBuilder));
    }

    public function testTwigTemplate()
    {
        $config = [
            'twig-template' => 'foo.twig',
        ];
        $this->webBuilder = new WebBuilder(new NullOutput(), 'build', $config, false);
        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigTemplate');
        $method->setAccessible(true);

        $this->assertSame('foo.twig', $method->invoke($this->webBuilder));
    }

    public function testTwigTemplateDefault()
    {
        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigTemplate');
        $method->setAccessible(true);

        $this->assertSame('index.html.twig', $method->invoke($this->webBuilder));
    }

    /**
     * @dataProvider dataGetDescSortedVersions
     */
    public function testGetDescSortedVersions($expected, $packages)
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('getDescSortedVersions');
        $method->setAccessible(true);

        $this->assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }

    public function dataGetHighestVersion()
    {
        $data = [];

        $data['test1 stable versions'] = [
            new Package('vendor/name', '2.0.1.0', '2.0.1'),
            [
                [
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                    new Package('vendor/name', '1.1.0.0', '1.1'),
                    new Package('vendor/name', '2.0.1.0', '2.0.1'),
                ],
            ],
        ];

        return $data;
    }

    /**
     * @dataProvider dataGetHighestVersion
     */
    public function testGetHighestVersion($expected, $packages)
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('getHighestVersion');
        $method->setAccessible(true);

        $this->assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }

    public function dataGroupPackagesByName()
    {
        $data = [];

        $data['test1 stable versions'] = [
            [
                'vendor/name' => [
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                ],
                'othervendor/othername' => [
                    new Package('othervendor/othername', '1.1.0.0', '1.1'),
                    new Package('othervendor/othername', '2.0.1.0', '2.0.1'),
                ],
            ],
            [
                [
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('othervendor/othername', '1.1.0.0', '1.1'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                    new Package('othervendor/othername', '2.0.1.0', '2.0.1'),
                ],
            ],
        ];

        return $data;
    }

    /**
     * @dataProvider dataGroupPackagesByName
     */
    public function testGroupPackagesByName($expected, $packages)
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('groupPackagesByName');
        $method->setAccessible(true);

        $this->assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }
}
