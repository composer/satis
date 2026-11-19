---
layout: default
permalink: /private-packagist
title: Private Packagist
---

# Private Packagist

[Private Packagist](https://packagist.com/?utm_source=composer.github.io&utm_medium=satis&utm_campaign=intro) is a
commercial Composer repository service by the creators of Composer and Packagist.org. Like Satis, it hosts your private
packages, and it adds access control, mirroring and security monitoring. Its revenue helps fund Composer and Packagist.org.
{: .lead}

Private Packagist is available as a cloud service with a 14-day free trial, or self-hosted on your own infrastructure
with a 30-day free trial.

## Satis and Private Packagist compared

|                       | Satis                                                       | Private Packagist                                             |
| --------------------- | ----------------------------------------------------------- | ------------------------------------------------------------- |
| Package updates       | Rebuild on a schedule or from your own hooks                | Webhooks update package information the moment you push       |
| Hosting               | You run the builds and serve the files                      | Managed cloud service, or self-hosted                         |
| Access control        | Whatever your web server provides, for the whole repository | Teams, per-package permissions and per-user tokens            |
| Separate repositories | A separate Satis configuration and build for each           | Suborganizations with their own packages, users and tokens    |
| Mirroring             | Metadata of selected packages, and archives if configured   | Packagist.org and any other repository, metadata and archives |
| Supply chain security | Only Composer's client-side policies, set in each project   | Server-side malware blocking, cooldowns and plugin rules      |
| Security advisories   | Not included                                                | Monitoring of your projects' `composer.lock` files            |
| Update review         | Not included                                                | Pull request comments listing all `composer.lock` changes     |
| Selling packages      | Not included                                                | Per-customer repositories, tokens and license restrictions    |
| Support               | Community, through GitHub issues                            | Email and chat support                                        |
| Cost                  | Free and open source                                        | Paid subscription                                             |

The [Satis vs. Private Packagist](https://packagist.com/features/satis-vs-packagist?utm_source=composer.github.io&utm_medium=satis&utm_campaign=comparison)
page on packagist.com has a more detailed comparison.

## What Private Packagist adds to Satis

### New versions without waiting for a rebuild

Satis only knows about a new tag or commit after the next build. Private Packagist synchronizes your repositories
through webhooks from GitHub, GitLab and Bitbucket, so `composer update` sees a new version right after you push,
without waiting for a cron job.

### Access control per person and per package

A Satis repository is a set of static files: everyone who can reach the repository can see every package in it.
Private Packagist gives each developer, and each CI or deployment environment, its own authentication token, so you
can revoke access for a single person without rotating a shared password. Teams get per-package permissions, and
teams, members and permissions can be synchronized with your GitHub, GitLab or Bitbucket organization.

### Separate repositories for clients and projects

With Satis, every additional repository needs its own configuration and build. In Private Packagist, suborganizations
are additional Composer repositories in your organization, each with its own URL, authentication tokens and list of
packages. Agencies use suborganizations to give each client access to only that client's packages, and teams use
them to keep apart projects that are on different major versions of a framework, such as Drupal or Magento.

### Mirroring of all your dependencies

Private Packagist mirrors and caches packages from Packagist.org, GitHub, Bitbucket and any other Composer
repository, such as repo.magento.com, including their zip archives. Composer then downloads every package from your
own repository, so you can deploy even when GitHub or another source is down.

### Security monitoring

Private Packagist checks the `composer.lock` files of your projects against security advisories, such as the PHP
Security Advisories Database, and alerts you by email, webhooks, Slack or Microsoft Teams when a vulnerability is
reported for a dependency you use.

### Supply chain security policies for your whole organization

A Satis repository serves whatever its last build produced. Apart from versions you leave out by hand with
[blacklist](config#blacklist), protection against malicious releases then depends on Composer's client-side dependency
policies, which require Composer 2.10 or newer (2.11 for the cooldown) and can be turned off in each project's
`composer.json`. Private Packagist enforces its security settings on the server, for every Composer version
and every project:

- Package versions flagged as malware are blocked within minutes of being flagged.
- A cooldown period holds back newly published releases, giving malware scanners time to catch a compromised release.
- Only the Composer versions you allow can access your repository.
- Only the packages on your allowlist can act as Composer plugins.

The [supply chain security](https://packagist.com/features/supply-chain-security?utm_source=composer.github.io&utm_medium=satis&utm_campaign=supplychain)
page on packagist.com describes these settings in detail.

### Review of dependency updates in pull requests

When a pull request updates your dependencies, Update Review comments with a table of all `composer.lock` changes,
linking to the code changes and changelogs. Update Review works with GitHub, GitLab and Bitbucket, including their
self-hosted editions.

### Package usage, install statistics and licenses

Private Packagist shows which of your applications use a package, keeps version-specific install statistics, and
lists the open-source licenses of the dependencies used across your organization.

### Selling packages to customers

If you distribute packages to customers, each customer gets a separate repository URL and authentication token.
You choose which packages a customer can access, and you can limit the updates a customer receives with a version
constraint or an expiration date to match your licensing model. Install statistics are available per customer.

### Support from the Composer team

Private Packagist includes email and chat support from the team that builds Composer.

## When Satis is enough

Satis remains a good fit when a small team shares a few private packages, everyone with access to the repository may
see all of them, and rebuilding on a schedule or from a hook is fast enough. Satis is free, runs anywhere PHP runs,
and its output is plain files that any web server can serve.

## Moving from Satis to Private Packagist

Private Packagist imports your existing Satis configuration, so you do not have to add your repositories one by one:

1. Create an organization on [packagist.com](https://packagist.com/?utm_source=composer.github.io&utm_medium=satis&utm_campaign=signup).
2. Under _Settings > Stored Credentials_, add the credentials Private Packagist needs to access your private
   repositories.
3. Open _Add Package > Json Import_, paste the contents of your `satis.json` and select those credentials.

Private Packagist then adds every package from your Satis configuration. The [setup documentation](https://packagist.com/docs/setup?utm_source=composer.github.io&utm_medium=satis&utm_campaign=setupdocs)
explains the remaining steps, such as configuring Composer to use your new repository.
