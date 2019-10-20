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

use Composer\Json\JsonFile;
use GuzzleHttp\Client;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GitlabPublisher extends Publisher
{
    /** @var array $authHeader Gitlab auth header. */
    protected $authHeader;

    /** @var integer $projectUrl Gitlab project url. */
    protected $projectUrl;

    public function __construct(OutputInterface $output, string $outputDir, array $config, bool $skipErrors, InputInterface $input)
    {
        parent::__construct($output, $outputDir, $config, $skipErrors, $input);

        $this->projectUrl = $this->getProjectUrl();
        $this->authHeader = $this->getAuthHeader();
        $this->outputDir = $outputDir;
        $this->uploadFilesToGitlab();
    }

    public function uploadFilesToGitlab() {
        $files = $this->findFilesToUpload($this->outputDir);

        $json = '';
        $attachments = [];

        foreach ($files as $file) {

            if (preg_match('/.json$/', $file, $fileMatches)) {
                $composer = new JsonFile($file);
                $composer = $composer->read();
            } else {
                // Build attachments to send
                $this->output->writeln("<options=bold,underscore>Uploading</> $file");
                $attachments[] = [
                    'contents' => base64_encode(file_get_contents($file)),
                    'filename' => basename($file),
                    'length' => filesize($file)
                ];
            }
        }

        /**
         * Build Gitlab request
         */
        $client = new Client([
            'timeout' => 20.0,
        ]);

        $composer = reset($composer);
        $packageName = urlencode($composer['name']);
        $apiUrl = $this->getProjectUrl() . '/api/v4/projects/' . $this->input->getOption('project-id') . "/packages/composer/" . $packageName;

        $response = $client->request(
            'PUT',
            $apiUrl, [
                'headers' => $this->getAuthHeader(),
                'body' => json_encode([
                    'name' => $composer['name'],
                    'version' => $composer['version'],
                    'json' => $composer,
                    'attachments' => $attachments,
                ])
            ]
        );

        if ($response->getStatusCode() == 200) {
            $this->output->writeln('<info>Package ' . $composer['name'] . ' ' . $composer['version'] . ' published ...</info>');
        }
    }

    /**
     * Find all files needed for this package
     *
     * @param string $uploadDir
     * @return array $files
     */
    public function findFilesToUpload($outputDir)
    {
        $dirs = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outputDir));
        $files = array();

        foreach ($dirs as $file) {
            if ($file->isDir()) {
                continue;
            }

            if (preg_match("/\.(json|tar|zip)*$/i", $file->getPathname(), $matches)) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * @param array $files
     */
    public function deleteFiles($files) {
        foreach ($files as $file) {
            unlink($file);
        }
    }

    private function getProjectUrl() {
        try {
            $url = $this->input->getOption('project-url');

            if(!empty($url)) {
                $projectUrl = parse_url($url);
            }

            return sprintf("%s://%s:%s", $projectUrl['scheme'], $projectUrl['host'], $projectUrl['port']);
        } catch (\Exception $e) {
            $this->output->writeln("<error>Set option '--project-url' or environment variable 'CI_PROJECT_URL' and make sure it is a valid url</error>");

            exit;
        }
    }

    private function getAuthHeader() {
        $tokenPrivate = $this->input->getOption('private-token');
        $tokenJob = getenv('CI_JOB_TOKEN');
        $authHeader =  $tokenPrivate ? ["Private-Token" => $tokenPrivate] : ["JOB-TOKEN" => $tokenJob];

        if(empty($tokenPrivate) && empty($tokenJob)) {
            $this->output->writeln("<error>Authentication not set. You have following options: \n * Empty will try to use 'CI_JOB_TOKEN' env var \n * Set cli option '--private-token' </error>");

            exit;
        }

        return $authHeader;
    }
}
