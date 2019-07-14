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

namespace Composer\Satis\Publisher;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GitlabPublisher extends Publisher
{
    /** @var integer $authHeader Gitlab auth header. */
    protected $authHeader;

    /** @var integer $projectId Gitlab project id. */
    protected $projectId;

    /** @var integer $projectUrl Gitlab project url. */
    protected $projectUrl;

    /** @var string $namespace Gitlab project namespace. */
    protected $namespace;

    public function __construct(OutputInterface $output, string $outputDir, bool $skipErrors, InputInterface $input = null)
    {
        parent::__construct($output, $outputDir, $skipErrors, $input);

        $this->projectId = getenv('CI_PROJECT_ID');
        $this->projectUrl = $this->getProjectUrl();
        $this->namespace = getenv('CI_PROJECT_NAMESPACE');
        $this->authHeader = $this->getAuthHeader();
    }

    private function getProjectUrl() {
        $projectUrl = parse_url(getenv('CI_PROJECT_URL'));
        return sprintf("%s://%s:%s", $projectUrl['scheme'], $projectUrl['host'], $projectUrl['port']);
    }

    private function getAuthHeader() {
        $tokenPrivate = getenv('PRIVATE_TOKEN');
        $tokenJob = getenv('PRIVATE_TOKEN');
        $authHeader =  $tokenPrivate ? ["Private-Token" => $tokenPrivate] : ["JOB-TOKEN" => $tokenJob];

        if(empty($tokenPrivate) && empty($tokenJob)) {
            $this->output->writeln("<error>Neither 'PRIVATE_TOKEN' nor 'CI_JOB_TOKEN' set. </error>");
            return;
        }

        return $authHeader;
    }
}
