<?php

namespace Composer\Satis\Service;

use Composer\Satis\Console\Application;
use Composer\Satis\Exception\AccessDeniedException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class Webhook
 */
class Webhook
{
    const GITHUB_WEBHOOK_SECRET = '12345678';
    const SOCIAL_POINT_SECRET   = '12345678';

    /**
     * @var Application
     */
    private $application;

    /**
     * @param Application $application
     */
    public function __construct(Application $application)
    {
        $this->application = $application;
    }
    /**
     * @param Request $request
     * @return bool
     */
    public function processRequest(Request $request)
    {
        if (!$this->isValidRequest($request)) {
            throw new AccessDeniedException();
        }

        $this->buildSatis($request);

        return true;
    }

    /**
     * @param Request $request
     */
    private function buildSatis(Request $request)
    {
        $packagesFilter = $this->getPackagesFilter($request);

        $input  = new ArrayInput(array(
            0               => 'build',
            'file'          => '/private/var/www/satis2/satis.json',
            'output-dir'    => '/private/var/www/satis2/web/',
            'packages'      => $packagesFilter,
        ));
        $output = new NullOutput();

        $this->application->run($input, $output);
    }

    private function getPackagesFilter(Request $request)
    {
        if (!$this->isValidGithubRequest($request)) {
            return array();
        }

        $payload = $request->getContent();
        $eventData = json_decode($payload, 1);

        $repositoryName = $eventData['repository']['full_name'];
    }

    /**
     * @param  Request $request
     * @return bool
     */
    private function isValidRequest(Request $request)
    {
        return $this->isValidGithubRequest($request) || $this->isValidSocialPointRequest($request);
    }

    /**
     * @param  Request $request
     * @return bool
     */
    private function isValidGithubRequest(Request $request)
    {
        $requestSignature = $request->headers->get('x-hub-signature');

        if (null === $requestSignature) {
            return false;
        }

        $payload   = $request->getContent();
        $signature = $this->getSignature($payload);

        return $requestSignature === $signature;
    }

    /**
     * @param  Request $request
     * @return bool
     */
    private function isValidSocialPointRequest(Request $request)
    {
        $requestSecret = $request->query->get('secret');

        return $requestSecret === static::SOCIAL_POINT_SECRET;
    }

    /**
     * @param  string $payload
     * @return string
     */
    private function getSignature($payload)
    {
        return 'sha1=' . hash_hmac('sha1', $payload, static::GITHUB_WEBHOOK_SECRET);
    }
}
