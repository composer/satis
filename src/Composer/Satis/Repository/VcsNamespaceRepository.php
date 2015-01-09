<?php
namespace Composer\Satis\Repository;

use Composer\Downloader\TransportException;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ArrayRepository;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\Vcs\VcsDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\VcsRepository;

/**
 * Class VcsNamespaceRepository
 *
 * @package Composer\Satis\Repository
 * @property VersionParser $versionParser
 */
class VcsNamespaceRepository extends VcsRepository
{
    /**
     * Implemented package namespace usage
     *
     * @see VcsRepository::initialize()
     * @throws InvalidRepositoryException
     */
    protected function initialize()
    {
        ArrayRepository::initialize();

        $verbose = $this->verbose;

        /** @var VcsDriver $driver */
        $driver = $this->getDriver();
        if (!$driver) {
            throw new \InvalidArgumentException('No driver found to handle VCS repository '.$this->url);
        }

        $this->versionParser = new VersionParser();
        if (!$this->loader) {
            $this->loader = new ArrayLoader($this->versionParser);
        }

        try {
            if ($driver->hasComposerFile($driver->getRootIdentifier())) {
                $data = $driver->getComposerInformation($driver->getRootIdentifier());
                $this->packageName = !empty($data['name']) ? $data['name'] : null;
            }
        } catch (\Exception $e) {
            if ($verbose) {
                $this->io->write('<error>Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage().'</error>');
            }
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $tag . '</comment>)';
            if ($verbose) {
                $this->io->write($msg);
            } else {
                $this->io->overwrite($msg, false);
            }

            // strip the release- prefix from tags if present
            $tag = str_replace('release-', '', $tag);

            if (!$parsedTag = $this->validateTag($tag)) {
                if ($verbose) {
                    $this->io->write('<warning>Skipped tag '.$tag.', invalid tag name</warning>');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($verbose) {
                        $this->io->write('<warning>Skipped tag '.$tag.', no composer file</warning>');
                    }
                    continue;
                }

                // manually versioned package
                if (isset($data['version'])) {
                    $data['version_normalized'] = $this->versionParser->normalize($data['version']);
                } else {
                    // auto-versioned package, read value from tag
                    //set version without namespace
                    $data['version'] = $this->_getBranchWithoutNamespace($tag);
                    $data['version_normalized'] = $parsedTag;
                }

                //set package namespace to generate package name based upon repository name
                $data['namespace'] = isset($data['namespace'])
                    ? $data['namespace'] : $this->_getBranchNamespace($tag);

                // make sure tag packages have no -dev flag
                $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
                $data['version_normalized'] = preg_replace('{(^dev-|[.-]?dev$)}i', '', $data['version_normalized']);

                // broken package, version doesn't match tag
                if ($data['version_normalized'] !== $parsedTag) {
                    if ($verbose) {
                        $this->io->write('<warning>Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json</warning>');
                    }
                    continue;
                }

                if ($verbose) {
                    $this->io->write('Importing tag '.$tag.' ('.$data['version_normalized'].')');
                }

                $this->addPackage($this->loader->load($this->preProcess($driver, $data, $identifier)));
            } catch (\Exception $e) {
                if ($verbose) {
                    $this->io->write('<warning>Skipped tag '.$tag.', '.($e instanceof TransportException ? 'no composer file was found' : $e->getMessage()).'</warning>');
                }
                continue;
            }
        }

        if (!$verbose) {
            $this->io->overwrite('', false);
        }

        foreach ($driver->getBranches() as $branch => $identifier) {
            $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $branch . '</comment>)';
            if ($verbose) {
                $this->io->write($msg);
            } else {
                $this->io->overwrite($msg, false);
            }

            if (!$parsedBranch = $this->validateBranch($branch)) {
                if ($verbose) {
                    $this->io->write('<warning>Skipped branch '.$branch.', invalid name</warning>');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($verbose) {
                        $this->io->write('<warning>Skipped branch '.$branch.', no composer file</warning>');
                    }
                    continue;
                }

                // branches are always auto-versioned, read value from branch name
                // set branch name without package namespace
                $data['version'] = $this->_getBranchWithoutNamespace($branch);
                //set package namespace to generate package name based upon repository name
                $data['namespace'] = isset($data['namespace'])
                    ? $data['namespace'] : $this->_getBranchNamespace($branch);
                $data['version_normalized'] = $parsedBranch;

                // make sure branch packages have a dev flag
                if ('dev-' === substr($parsedBranch, 0, 4) || '9999999-dev' === $parsedBranch) {
                    $data['version'] = 'dev-' . $data['version'];
                } else {
                    $data['version'] = preg_replace('{(\.9{7})+}', '.x', $parsedBranch);
                }

                if ($verbose) {
                    $this->io->write('Importing branch '.$branch.' ('.$data['version'].')');
                }

                $packageData = $this->preProcess($driver, $data, $identifier);
                $package = $this->loader->load($packageData);
                if ($this->loader instanceof ValidatingArrayLoader && $this->loader->getWarnings()) {
                    throw new InvalidPackageException($this->loader->getErrors(), $this->loader->getWarnings(), $packageData);
                }
                $this->addPackage($package);
            } catch (TransportException $e) {
                if ($verbose) {
                    $this->io->write('<warning>Skipped branch '.$branch.', no composer file was found</warning>');
                }
                continue;
            } catch (\Exception $e) {
                if (!$verbose) {
                    $this->io->write('');
                }
                $this->branchErrorOccurred = true;
                $this->io->write('<error>Skipped branch '.$branch.', '.$e->getMessage().'</error>');
                $this->io->write('');
                continue;
            }
        }
        $driver->cleanup();

        if (!$verbose) {
            $this->io->overwrite('', false);
        }

        if (!$this->getPackages()) {
            throw new InvalidRepositoryException('No valid composer.json was found in any branch or tag of '.$this->url.', could not load a package from it.');
        }
    }

    /**
     * Make proper package data
     *
     * @param VcsDriverInterface $driver
     * @param array $data
     * @param string $identifier
     * @return array
     */
    protected function preProcess(VcsDriverInterface $driver, array $data, $identifier)
    {
        // keep the name of the main identifier for all packages
        if ($this->packageName) {
            $data['name'] = $this->packageName . '-' . strtolower($data['namespace']);
        }

        if (!isset($data['dist'])) {
            $data['dist'] = $driver->getDist($identifier);
        }
        if (!isset($data['source'])) {
            $data['source'] = $driver->getSource($identifier);
        }

        return $data;
    }

    /**
     * Get normalized tag name
     *
     * It will return an empty result if it's not valid
     *
     * @param string $version
     * @return bool
     */
    protected function validateTag($version)
    {
        try {
            if (!strpos($version, '/')) {
                return false;
            }
            return $this->versionParser->normalize(
                $this->_getBranchWithoutNamespace($version)
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get normalized branch name
     *
     * It will return an empty result if it's not valid
     *
     * @param string $branch
     * @return bool
     */
    protected function validateBranch($branch)
    {
        try {
            if (!strpos($branch, '/')) {
                return false;
            }
            return $this->versionParser->normalizeBranch(
                $this->_getBranchWithoutNamespace($branch)
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get branch name (without package namespace)
     *
     * @param string $branch
     * @return string
     */
    protected function _getBranchWithoutNamespace($branch)
    {
        $arr = explode('/', $branch);
        array_shift($arr);
        return implode('/', $arr);
    }

    /**
     * Get branch namespace
     *
     * @param string $branch
     * @return string
     */
    protected function _getBranchNamespace($branch)
    {
        $arr = explode('/', $branch);
        return array_shift($arr);
    }
}