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
use Twig\Extra\Html\HtmlExtension;
use Twig\Loader\ArrayLoader;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilderTest extends TestCase
{
    protected WebBuilder $webBuilder;

    public function setUp(): void
    {
        $this->webBuilder = new WebBuilder(new NullOutput(), 'build', [], false);
    }

    /**
     * @return array<string, mixed>
     */
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

    public function testTwigEnvironment(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $twig->addExtension(new HtmlExtension());
        $this->webBuilder->setTwigEnvironment($twig);

        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigEnvironment');
        $method->setAccessible(true);

        self::assertSame($twig, $method->invoke($this->webBuilder));
    }

    public function testTwigEnvironmentDefault(): void
    {
        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigEnvironment');
        $method->setAccessible(true);

        self::assertInstanceOf('\Twig\Environment', $method->invoke($this->webBuilder));
    }

    public function testTwigTemplate(): void
    {
        $config = [
            'twig-template' => 'foo.twig',
        ];
        $this->webBuilder = new WebBuilder(new NullOutput(), 'build', $config, false);
        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigTemplate');
        $method->setAccessible(true);

        self::assertSame('foo.twig', $method->invoke($this->webBuilder));
    }

    public function testTwigTemplateDefault(): void
    {
        $reflection = new \ReflectionClass($this->webBuilder);
        $method = $reflection->getMethod('getTwigTemplate');
        $method->setAccessible(true);

        self::assertSame('index.html.twig', $method->invoke($this->webBuilder));
    }

    /**
     * @dataProvider dataGetDescSortedVersions
     *
     * @param string[] $expected
     * @param array<string, mixed> $packages
     */
    public function testGetDescSortedVersions(array $expected, array $packages): void
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('getDescSortedVersions');
        $method->setAccessible(true);

        self::assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataGetHighestVersion(): array
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
     *
     * @param array<string, mixed> $packages
     */
    public function testGetHighestVersion(Package $expected, array $packages): void
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('getHighestVersion');
        $method->setAccessible(true);

        self::assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataGroupPackagesByName(): array
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
     *
     * @param string[] $expected
     * @param array<string, mixed> $packages
     */
    public function testGroupPackagesByName(array $expected, array $packages): void
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('groupPackagesByName');
        $method->setAccessible(true);

        self::assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }
}
