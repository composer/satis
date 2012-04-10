<?php

/*
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Satis;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * The Compiler class compiles satis into a phar
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Compiler
{
    /**
     * Compiles satis into a single phar file
     *
     * @throws \RuntimeException
     * @param string $pharFile The full path to the file to create
     */
    public function compile($pharFile = 'satis.phar')
    {
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $phar = new \Phar($pharFile, 0, 'satis.phar');
        $phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->notName('Compiler.php')
            ->in(__DIR__.'/../../')
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->in(__DIR__.'/../../../vendor/')
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $this->addSatisBin($phar);

        // Stubs
        $phar->setStub($this->getStub());

        $phar->stopBuffering();

        $phar->compressFiles(\Phar::GZ);

        $this->addFile($phar, new \SplFileInfo(__DIR__.'/../../../LICENSE'), false);

        unset($phar);
    }

    private function addFile($phar, $file, $strip = true)
    {
        $path = str_replace(dirname(dirname(dirname(__DIR__))).DIRECTORY_SEPARATOR, '', $file->getRealPath());

        if ($strip) {
            $content = php_strip_whitespace($file);
        } else {
            $content = "\n".file_get_contents($file)."\n";
        }

        $phar->addFromString($path, $content);
    }

    private function addSatisBin($phar)
    {
        $content = file_get_contents(__DIR__.'/../../../bin/satis');
        $content = preg_replace('{^#!/usr/bin/env php\s*}', '', $content);
        $phar->addFromString('bin/satis', $content);
    }

    private function getStub()
    {
        return <<<'EOF'
#!/usr/bin/env php
<?php
/*
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view
 * the license that is located at the bottom of this file.
 */

Phar::mapPhar('satis.phar');

require 'phar://satis.phar/bin/satis';

__HALT_COMPILER();
EOF;
    }
}
