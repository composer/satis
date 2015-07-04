<?php
namespace Composer\Satis\PackageSelection;
use Composer\DependencyResolver\Pool;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * LinkResolver takes all the Links contained in a PackageSelection,
 * retrieves the matching Packages from the package pool and adds them
 * to the PackageSelection as well.
 *
 * For every Package found, it can use one or several LinkProvider to
 * pick further (transitive) Links and process those as well.
 */
class LinkResolver {

    /** @var LinkProvider[] */
    protected $linkProvider = array();

    protected $output;
    protected $pool;

    protected $foundLinks;
    protected $links;

    public function __construct(Pool $pool, OutputInterface $output)
    {
        $this->pool = $pool;
        $this->output = $output;
    }

    public function addLinkProvider(LinkProvider $linkProvider)
    {
        $this->linkProvider[] = $linkProvider;
    }

    public function work(PackageSelection $selection)
    {
        $this->links = $selection->getLinks();
        $this->foundLinks = array();

        while ($link = array_shift($this->links)) {

            if ($this->output->isVerbose()) {
                $this->output->writeln("<info>Following link {$link->getTarget()} {$link->getPrettyConstraint()}.</info>");
            }

            $this->followLink($link, $selection);
        }
    }

    protected function followLink(Link $link, PackageSelection $selection)
    {
        $matches = $this->pool->whatProvides($link->getTarget(), $link->getConstraint(), true);

        if (!$matches) {
            $this->output->writeln("<error>The {$link->getTarget()} {$link->getPrettyConstraint()} requirement did not match any package</error>");
            return;
        }

        foreach ($matches as $package) {
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            if ($this->output->isVerbose()) {
                $this->output->writeln("<info>Found {$package->getPrettyName()} {$package->getPrettyVersion()} when following the {$link->getTarget()} {$link->getPrettyConstraint()} link.</info>");
            }

            $this->processPackage($package, $selection);
        }
    }

    protected function processPackage(PackageInterface $package, PackageSelection $selection) {

        $selection->considerPackage($package);

        foreach ($this->getDependencyLinks($package) as $dependencyLink) {

            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $dependencyLink->getTarget())) {
                continue; // don't follow platform packages
            }

            $hash = $this->hashLink($dependencyLink);

            if (!isset($this->foundLinks[$hash])) {
                $this->foundLinks[$hash] = true;
                $this->links[] = $dependencyLink;

                if ($this->output->isVerbose()) {
                    $this->output->writeln("<info>Found new link {$dependencyLink->getTarget()} {$dependencyLink->getPrettyConstraint()} while at package {$package->getPrettyName()} {$package->getPrettyVersion()}</info>");
                }
            }

        }
    }

    private function hashLink(Link $link) {
        return $link->getTarget().' '.$link->getConstraint();
    }

    protected function getDependencyLinks(PackageInterface $package)
    {
        $req = array();

        foreach ($this->linkProvider as $linkProvider) {
            $req = array_merge($req, $linkProvider->getLinks($package));
        }

        return $req;
    }
}
