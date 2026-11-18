---
layout: default
permalink: /private-packagist
title: Private Packagist
---

# Private Packagist

[Private Packagist](https://packagist.com/) is a commercial Composer repository service, made by the creators of
Composer and Packagist.org. Like Satis, it hosts your private packages, but it is a managed service rather than a
static generator, so it adds features that a set of static files cannot offer. Part of its revenue funds the
development and hosting of Composer and Packagist.org.
{: .lead}

It is available as a cloud service or self-hosted, with a 14-day free trial of the cloud service.

## At a glance

|                     | Satis                                                       | Private Packagist                                             |
| ------------------- | ----------------------------------------------------------- | ------------------------------------------------------------- |
| Package updates     | Rebuild on a schedule or from your own hooks                | Webhooks update package information the moment you push       |
| Hosting             | You run the builds and serve the files                      | Managed cloud service, or self-hosted                         |
| Access control      | Whatever your web server provides, for the whole repository | Teams, per-package permissions and per-user tokens            |
| Mirroring           | Archives of selected packages, if configured                | Packagist.org and any other repository, metadata and archives |
| Security advisories | Not included                                                | Monitoring of your projects' `composer.lock` files            |
| Selling packages    | Not included                                                | Per-customer repositories, tokens and license restrictions    |
| Support             | Community, through GitHub issues                            | Email and chat support                                        |
| Cost                | Free and open source                                        | Paid subscription                                             |

## What it adds

### No rebuilds

Satis only knows about a new tag or commit after the next build. Private Packagist synchronizes your repositories
through webhooks from GitHub, GitLab and Bitbucket, so `composer update` sees a new version right after you push,
without waiting for a cron job.

### Access control per person and per package

A Satis repository is a set of static files: everyone who can reach it can see every package in it. Private Packagist
gives each developer, and each CI or deployment environment, its own authentication token, so you can revoke access
for one person without rotating a shared password. Teams get per-package permissions, and teams, members and
permissions can be synchronized with your GitHub, GitLab or Bitbucket organization.

### Mirroring of all your dependencies

Private Packagist mirrors and caches packages from Packagist.org, GitHub, Bitbucket and any other Composer
repository, such as repo.magento.com, including their zip archives. Your installs then download every package from your
own repository, so you can deploy even when GitHub or another source is down.

### Security monitoring

Private Packagist checks the `composer.lock` files of your projects against security advisories, such as the PHP
Security Advisories Database, and alerts you by email, webhooks, Slack or Microsoft Teams when a vulnerability is
reported for a dependency you use.

### Reviewing dependency updates

When a pull request updates your dependencies, Update Review comments with a table of all `composer.lock` changes,
linking to the code changes and changelogs. It works with GitHub, GitLab and Bitbucket, including their self-hosted
editions.

### Insight into your packages

Private Packagist shows which of your applications use a package, keeps version-specific install statistics, and
reviews the licenses of your private and mirrored packages.

### Selling packages to customers

If you distribute packages to customers, each customer gets its own repository URL and authentication token, access to
individual packages, and access limited by version constraint or timeframe to match your licensing model, with
per-customer install statistics.

### Support

Private Packagist offers email and chat support from the people who build Composer.

## When Satis is enough

Satis remains a good fit when a small team shares a few private packages, everyone with access to the repository may
see all of them, and rebuilding on a schedule or from a hook is fast enough. It is free, runs anywhere PHP runs, and
its output is plain files that any web server can serve.

## Moving from Satis

Private Packagist can import a Satis configuration, so you do not have to add your repositories one by one. See
[packagist.com](https://packagist.com/) for the current features, pricing and the free trial.
