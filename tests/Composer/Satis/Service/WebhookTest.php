<?php

namespace Composer\Satis\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Class WebhookTest
 */
class WebhookTest extends \PHPUnit_Framework_TestCase
{
    public function testGithubRequest()
    {
        $query = $request = $attributes = $cookies = $files = array();

        $server = array(
            'HTTP_Request URL'          => 'http://ianmonge2.vservers.es/hook.php',
            'HTTP_Request method'       => 'POST',
            'HTTP_content-type'         => 'application/json',
            'HTTP_Expect'               => '',
            'HTTP_User-Agent'           => 'GitHub-Hookshot/d883a79',
            'HTTP_X-GitHub-Delivery'    => '8c80034c-2def-11e4-8fd7-640b2566f5d9',
            'HTTP_X-GitHub-Event'       => 'push',
            'HTTP_X-Hub-Signature'      => 'sha1=05e36cfa13f28379cf5cae921fd3ea95f8b29462',
        );
        $content = $this->getPayload();

        $request = new Request($query, $request, $attributes, $cookies, $files, $server, $content);

        $webhook = new Webhook();
        $result = $webhook->processRequest($request);

        $this->assertTrue($result);
    }

    public function testSocialPointRequest()
    {
        $query = array(
            'secret' => '12345678',
        );

        $request = new Request($query);

        $webhook = new Webhook();
        $result = $webhook->processRequest($request);

        $this->assertTrue($result);
    }

    /**
     * @return string
     */
    private function getPayload()
    {
        return '{"ref":"refs/heads/develop","after":"45d46f7b1f5a5ca64943b5aa095f7cd0e2f6a26c","before":"4bc5b6fda7e8fee19a8f6d2571945384aa0a33e7","created":false,"deleted":false,"forced":false,"compare":"https://github.com/socialpoint/sp-platform-darkbattles/compare/4bc5b6fda7e8...45d46f7b1f5a","commits":[{"id":"45d46f7b1f5a5ca64943b5aa095f7cd0e2f6a26c","distinct":true,"message":"TEST","timestamp":"2014-08-27T15:39:08+02:00","url":"https://github.com/socialpoint/sp-platform-darkbattles/commit/45d46f7b1f5a5ca64943b5aa095f7cd0e2f6a26c","author":{"name":"Ian Monge","email":"ian.monge@socialpoint.es","username":"sp-ian-monge"},"committer":{"name":"Ian Monge","email":"ian.monge@socialpoint.es","username":"sp-ian-monge"},"added":[],"removed":[],"modified":[]}],"head_commit":{"id":"45d46f7b1f5a5ca64943b5aa095f7cd0e2f6a26c","distinct":true,"message":"TEST","timestamp":"2014-08-27T15:39:08+02:00","url":"https://github.com/socialpoint/sp-platform-darkbattles/commit/45d46f7b1f5a5ca64943b5aa095f7cd0e2f6a26c","author":{"name":"Ian Monge","email":"ian.monge@socialpoint.es","username":"sp-ian-monge"},"committer":{"name":"Ian Monge","email":"ian.monge@socialpoint.es","username":"sp-ian-monge"},"added":[],"removed":[],"modified":[]},"repository":{"id":8503552,"name":"sp-platform-darkbattles","full_name":"socialpoint/sp-platform-darkbattles","owner":{"name":"socialpoint","email":""},"private":true,"html_url":"https://github.com/socialpoint/sp-platform-darkbattles","description":"Dark Battles = League of warriors","fork":false,"url":"https://github.com/socialpoint/sp-platform-darkbattles","forks_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/forks","keys_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/keys{/key_id}","collaborators_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/collaborators{/collaborator}","teams_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/teams","hooks_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/hooks","issue_events_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/issues/events{/number}","events_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/events","assignees_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/assignees{/user}","branches_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/branches{/branch}","tags_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/tags","blobs_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/git/blobs{/sha}","git_tags_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/git/tags{/sha}","git_refs_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/git/refs{/sha}","trees_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/git/trees{/sha}","statuses_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/statuses/{sha}","languages_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/languages","stargazers_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/stargazers","contributors_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/contributors","subscribers_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/subscribers","subscription_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/subscription","commits_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/commits{/sha}","git_commits_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/git/commits{/sha}","comments_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/comments{/number}","issue_comment_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/issues/comments/{number}","contents_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/contents/{+path}","compare_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/compare/{base}...{head}","merges_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/merges","archive_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/{archive_format}{/ref}","downloads_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/downloads","issues_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/issues{/number}","pulls_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/pulls{/number}","milestones_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/milestones{/number}","notifications_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/notifications{?since,all,participating}","labels_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/labels{/name}","releases_url":"https://api.github.com/repos/socialpoint/sp-platform-darkbattles/releases{/id}","created_at":1362149943,"updated_at":"2014-08-26T13:20:44Z","pushed_at":1409146760,"git_url":"git://github.com/socialpoint/sp-platform-darkbattles.git","ssh_url":"git@github.com:socialpoint/sp-platform-darkbattles.git","clone_url":"https://github.com/socialpoint/sp-platform-darkbattles.git","svn_url":"https://github.com/socialpoint/sp-platform-darkbattles","homepage":"","size":44817,"stargazers_count":0,"watchers_count":0,"language":"PHP","has_issues":true,"has_downloads":true,"has_wiki":true,"forks_count":9,"mirror_url":null,"open_issues_count":10,"forks":9,"open_issues":10,"watchers":0,"default_branch":"develop","stargazers":0,"master_branch":"develop","organization":"socialpoint"},"pusher":{"name":"sp-ian-monge","email":"ian.monge@socialpoint.es"}}';
    }
}
