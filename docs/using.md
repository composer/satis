---
layout: default
permalink: using
title: Using
---

# Using

## Setup

For example, let's assume you have a few packages you want to reuse across your
company but don't really want to open-source. You would first define a Satis
configuration: a JSON file with an arbitrary name that lists your curated
[repositories](https://getcomposer.org/doc/05-repositories.md).

Here is an example configuration. It holds a few VCS repositories, but they
could be any type of [repository](https://getcomposer.org/doc/05-repositories.md).
It uses `"require-all": true`, which selects all versions of all packages in
the repositories you defined.

By default Satis looks for `satis.json` in the current working directory. The
`name` and `homepage` keys are required. The `name` must be a valid package
name such as `acme/satis`, see [name](config#name).

```json
{
    "name": "acme/satis",
    "homepage": "https://packages.example.org",
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo" },
        { "type": "vcs", "url": "https://svn.example.org/private/repo" },
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo2" }
    ],
    "require-all": true
}
```

If you want to cherry-pick packages, list the ones you want in your Satis
repository under the `require` key, as in a `composer.json`. Use a `"*"`
constraint to select all versions, or another constraint to select specific
ones:

```json
{
    "name": "acme/satis",
    "homepage": "https://packages.example.org",
    "repositories": [
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo" },
        { "type": "vcs", "url": "https://svn.example.org/private/repo" },
        { "type": "vcs", "url": "https://github.com/mycompany/privaterepo2" }
    ],
    "require": {
        "company/package": "*",
        "company/package2": "*",
        "company/package3": "2.0.0"
    }
}
```

Once you've done this, run:

```sh
php bin/satis build <configuration-file> <output-dir>
```

The examples below use `satis.json` as the configuration file and `web/` as the
output directory.

Once you have ironed out the process, you would typically run this command as a
cron job on a server. It then keeps your package information up to date, much
like Packagist does.

Note that if your private packages are hosted on GitHub, your server should
have an SSH key that gives it access to those packages, and you should add the
`--no-interaction` (or `-n`) flag to the command so that it falls back to SSH
key authentication instead of prompting for a password. This is also a good
trick for continuous integration servers.

Set up a virtual host that points to the output directory, say
`packages.example.org`. For a temporary solution, you can also use PHP's
built-in web server: `php -S localhost:8000 -t web/`.

Composer reads the metadata of tagged versions from
`p2/<vendor>/<package>.json` and that of dev versions (branches) from
`p2/<vendor>/<package>~dev.json`. Make sure your web server serves the
`~dev.json` files: some configurations refuse URLs that contain a `~`, and
versions such as `dev-master` then cannot be found.

### Partial updates

You can tell Satis to update only particular packages, or to process only the
repositories with a given URL. This cuts down the time it takes to rebuild the
repository and is helpful if you use (custom) webhooks to trigger rebuilds
whenever code is pushed to one of your repositories.

To rebuild only particular packages, pass the package names on the command
line:

```sh
php bin/satis build satis.json web/ this/package that/other-package
```

Satis cannot know which packages a remote repository (such as `vcs` or
`composer`) holds without reading it, so for a partial update it only scans the
remote repositories whose `name` is one of the requested packages. Give each of
them the name of the package it contains:

```json
{
    "repositories": [
        {
            "name": "company/privaterepo",
            "type": "vcs",
            "url": "https://github.com/mycompany/privaterepo"
        },
        {
            "name": "private/repo",
            "type": "vcs",
            "url": "https://svn.example.org/private/repo"
        },
        {
            "name": "mycompany/privaterepo2",
            "type": "vcs",
            "url": "https://github.com/mycompany/privaterepo2"
        }
    ]
}
```

Remote repositories without a matching `name` are not scanned for the requested
packages; they are only used to resolve dependencies when
`require-dependencies` or `require-dev-dependencies` is enabled. If no
repository matches, the build fails with "Could not find any repositories
config with "name" matching your package(s) filter".

Repositories that Satis can read without any network access (`artifact`, `path`
and `package`) are matched on the package names they actually contain, so they
need no `name`.

To scan only a single repository and update all packages found in it, pass its
URL with the `--repository-url` option. Repeat the option to scan several
repositories:

```sh
php bin/satis build --repository-url https://only.my/repo.git satis.json web/
```

The URL must be exactly the `url` of the repository in the configuration
(a trailing slash makes a difference). By default, dependencies are still
resolved from all repositories; add `--repository-strict` to resolve them only
from the selected ones. `--repository-url` cannot be combined with package
arguments or with `--branch`.

The previous package metadata is merged with the refreshed packages to rebuild
the repository indexes. Package files are only written when their contents
change.

You can also narrow the update to a single branch or version range of specific
packages:

```sh
php bin/satis build --branch main satis.json web/ acme/pkg
php bin/satis build --branch 1.x satis.json web/ acme/pkg
```

When `--branch` is given with a package filter, only the versions of the
specified packages that match the given branch (e.g. `dev-main`) or constraint
(e.g. `~1.0`) are refreshed. All other packages and branches are kept as they
are in the existing build.

### Strict builds

Some problems only produce a warning and the build still exits with code 0:

- a VCS repository whose cached mirror could not be updated ("Failed to update
  ..., package information from this repository may be outdated"); Composer's
  git, hg and fossil drivers then fall back to the cached mirror
- a package listed in `require` that matched no package ("The ... requirement
  did not match any package")

Pass `--strict` to make the build fail on these, for example in CI:

```sh
php bin/satis build --strict satis.json web/
```

The build still runs to completion and writes its output, then exits with
code 1 and lists what went wrong. Only `require` entries of the Satis
configuration count; unmatched requirements of dependencies (with
`require-dependencies`) and platform requirements such as `php` do not. With
`require-all` no requirement is checked, since all packages of the repositories
are selected instead of the `require` entries. With `--repository-url` no
requirement is checked either, since packages served by the other repositories
never match during a partial update.

A failed mirror update goes unnoticed when the same VCS repository is also
listed in a `composer.json` that the build reads: your project's
`composer.json` when Satis runs as a Composer plugin, or otherwise a
`composer.json` in the working directory. Satis then scans the repository as
registered by that `composer.json`, and its warnings bypass `--strict`.

`--skip-errors` keeps the build going after errors that would otherwise abort
it, such as a configuration file that does not match the schema, a repository
that cannot be cloned or read, a dependency lookup that fails or a package that
cannot be archived. Combined with `--strict`, the build still runs to
completion and writes its output, but each skipped error is listed as well and
the build exits with code 1.

## Usage

In your projects, all you need to add now is your own Composer repository with
`packages.example.org` as its URL. You can then require your private packages
and everything should work smoothly. You no longer need to list all your
repositories in every project, only this one repository, which keeps itself up
to date.

```json
{
    "repositories": [{ "type": "composer", "url": "https://packages.example.org/" }],
    "require": {
        "company/package": "1.2.0",
        "company/package2": "1.5.2",
        "company/package3": "dev-master"
    }
}
```

### Security

A TLS certificate on your server encrypts the connection, but anyone who knows
the URL can still read the repository. To restrict who can read it, host it
over SSH, require a client certificate, or protect it with HTTP basic auth (see
[Authentication](#authentication)) or a token header. In your project, use the
`options` parameter of the repository to set the connection options for the
server.

Example of a repository served over SSH (requires the SSH2 PECL extension):

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "ssh2.sftp://example.org",
            "options": {
                "ssh2": {
                    "username": "composer",
                    "pubkey_file": "/home/composer/.ssh/id_rsa.pub",
                    "privkey_file": "/home/composer/.ssh/id_rsa"
                }
            }
        }
    ]
}
```

> **Tip:** See [ssh2 context options] for more information.

Example of HTTPS with a client certificate:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org",
            "options": {
                "ssl": {
                    "local_cert": "/home/composer/.ssl/composer.pem"
                }
            }
        }
    ]
}
```

> **Tip:** See [ssl context options] for more information.

Example of a custom HTTP header for token authentication:

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://example.org",
            "options": {
                "http": {
                    "header": ["API-TOKEN: YOUR-API-TOKEN"]
                }
            }
        }
    ]
}
```

### Authentication

When your private repositories are password protected, you can store the
credentials permanently. The first time Composer needs to authenticate against
a domain, it prompts you for a username and password and asks whether to store
them.

You can store them either globally in `COMPOSER_HOME/auth.json` (run
`composer config --global home` to see where [COMPOSER_HOME] is on your
machine) or in an `auth.json` file next to your Satis configuration file (e.g.
`satis.json`). The credentials in the `auth.json` next to the configuration
file take precedence over the global ones and over those in the `config` block
of the configuration file. An `auth.json` is only read next to a configuration
file on disk, not next to one fetched over HTTP(S). When Satis runs as a
Composer plugin, Composer reads the `auth.json` next to your project's
`composer.json` instead.

You can also set the credentials by hand with the `config` command, for
example to set up a production machine for non-interactive builds. To set
credentials for example.org, run:

```sh
composer config --global http-basic.example.org username password
```

That stores them in the global `COMPOSER_HOME/auth.json`. To store them in the
`auth.json` next to your Satis configuration file instead, point Composer at
that configuration file:

```sh
composer config --file=satis.json http-basic.example.org username password
```

You can also pass the credentials in the `COMPOSER_AUTH` environment variable,
as a JSON object with the contents of an `auth.json` file:

```sh
COMPOSER_AUTH='{"http-basic": {"example.org": {"username": "username", "password": "password"}}}' \
  php bin/satis build satis.json web/
```

When several of these hold credentials for the same host, they take precedence
in this order: `COMPOSER_AUTH`, then the `auth.json` next to the configuration
file, then the `config` block of the configuration file, then the global
`COMPOSER_HOME/auth.json`.

### Downloads

When GitHub, GitLab or Bitbucket repositories are mirrored in your Satis
repository, the build includes the location of the downloads these platforms
provide. This means that the repository and your setup depend on the
availability of these services.

At the same time, code that is hosted elsewhere (on another service, or for
example in Subversion) has no downloads available, so installations usually
take a lot longer.

To make Satis create downloads for all your packages (Git, Mercurial and
Subversion), add the following to your `satis.json`:

```json
{
    "archive": {
        "directory": "dist",
        "format": "tar",
        "prefix-url": "https://amazing.cdn.example.org",
        "skip-dev": true
    }
}
```

#### Options explained

See [archive](config#archive) for the full reference.

- `directory`: required, the directory of the dist files, inside the
  `output-dir`. It is also part of the download URLs.
- `format`: optional, `zip` (default) or `tar`
- `prefix-url`: optional, the base URL of the downloads, `homepage` by default;
  `directory` is appended to it
- `skip-dev`: optional, `false` by default; when `true`, Satis does not create
  downloads for branches
- `absolute-directory`: optional, a _local_ directory where the dist files are
  written instead of `output-dir`/`directory`. The download URLs still use
  `directory`, so serve this directory at `<prefix-url>/<directory>`.
- `whitelist`: optional, a list of package names; if set, Satis only creates
  downloads for these packages (use `*` for wildcard matching)
- `blacklist`: optional, a list of package names; Satis does not create
  downloads for these packages (use `*` for wildcard matching)
- `checksum`: optional, `true` by default; when `false`, Satis does not publish
  the SHA-1 checksum of the dist files
- `rearchive`: optional, `true` by default; when `false`, packages that already
  have a zip or tar dist are served with that archive instead of a rebuilt one
- `ignore-filters`: optional, `false` by default; when `true`, the archives
  also contain the files that the package excludes from its archives
- `override-dist-type`: optional, `false` by default; when `true`, the archive
  file names contain the archive `format` instead of the original dist type

Once enabled, the downloads of the archived packages, including those from
GitHub, GitLab and Bitbucket, are replaced with a _local_ version. Packages
that are not archived keep their original download: branches with `skip-dev`,
packages left out by `whitelist` or `blacklist`, and metapackages.

#### prefix-url

Prefixing the URL with another host is especially helpful if the downloads end
up in a private Amazon S3 bucket or on a CDN host. A CDN can greatly improve
download times and therefore package installation.

Example: a `prefix-url` of `https://my-bucket.s3.amazonaws.com` (and
`directory` set to `dist`) creates download URLs like
`https://my-bucket.s3.amazonaws.com/dist/vendor/package/vendor-package-version-ref.zip`.

#### Checksums and rebuilds

An archive that Satis builds from a package is not byte-identical when it is
built again, so it gets a different SHA-1 checksum. Lock files that recorded
the old checksum then fail with "The checksum verification of the file
failed". Satis only builds an archive if its file does not exist yet, so to
avoid this:

- keep the `directory` (or `absolute-directory`) between builds, for example
  on a persistent volume when you build in CI or in a container,
- set `checksum` to `false`, so that no checksum is published and new lock
  files do not record one, or
- set `rearchive` to `false`, so that packages with a zip or tar dist are
  served with their original archive instead of a rebuilt one.

Lock files that already contain an outdated checksum need a `composer update`
of the affected packages.

#### Artifact repositories

Without an `archive` block, the dist URL of a package from an `artifact`
repository is the path of its zip file on the machine that runs Satis, which
Composer clients cannot download. Add an `archive` block so the packages are
served by Satis, and set `rearchive` to `false` to serve the zip files as they
are instead of building new archives:

```json
{
    "repositories": [{ "type": "artifact", "url": "artifacts/" }],
    "archive": {
        "directory": "dist",
        "rearchive": false
    }
}
```

#### Purge

Archives that are no longer part of the repository, for example of earlier
commits of a branch or of packages removed from the configuration, stay in the
archive directory. The `purge` command deletes the archives that the last build
no longer references:

```sh
php bin/satis purge satis.json web/
```

Add a third argument to list these archives without deleting them:

```sh
php bin/satis purge satis.json web/ dry-run
```

Run it with the same configuration as the build. It reads the packages of the
last build from `<output-dir>/packages.json`, and refuses to delete anything
when it finds none there. It recognizes archives by the `prefix-url` or
`homepage` (or `SATIS_HOMEPAGE`) in their URL, and only looks in
`<output-dir>/<directory>`, not in `absolute-directory`.

Projects whose `composer.lock` still refers to a deleted archive, typically an
old commit of a dev version, can no longer install it and need a
`composer update` of that package.

### Web outputs

- `output-html`: optional, `true` by default; when `false`, Satis does not
  generate the `<output-dir>/index.html` page.
- `twig-template`: optional, the path to a custom [Twig] template for the
  `<output-dir>/index.html` page.
- `allow-seo-indexing`: optional, `false` by default; when `true`, Satis allows
  search engines to index the generated page.

While working on a custom `twig-template`, you can regenerate only the
`<output-dir>/index.html` page from the packages of the previous build, without
scanning any repository:

```sh
php bin/satis build --html-only satis.json web/
```

Satis reads the packages back from the existing `packages.json` and its
include, provider or `p2/` metadata files, and leaves those files and any
archives untouched. As it is an explicit request for the page, `--html-only`
builds it even when `output-html` is `false`. It fails when `output-dir` holds
no previous build, or one built with `includes` and `providers` disabled and
`available-package-patterns` set, as its `packages.json` then lists no
packages. It cannot be combined with `--no-html-output`, `--repository-url`,
`--branch`, `--strict` or package arguments.

### Abandoned packages

To mark some packages as abandoned, add the following to your `satis.json`:

```json
{
    "abandoned": {
        "company/package": true,
        "company/package2": "company/newpackage"
    }
}
```

The `true` value marks the package as abandoned without a replacement, while
the `"company/newpackage"` value marks it as replaced by the
`company/newpackage` package. Composer warns about abandoned packages when it
installs them, and the web page marks them as well.

Packages that declare themselves abandoned in their own `composer.json` are
marked abandoned too.

### Publication times

Composer's [cooldown policy](https://getcomposer.org/doc/06-config.md#cooldown)
holds back versions that were published only recently. It relies on the
`published-time` field, which only a Composer repository can provide, and
refuses to update when a version from a Composer repository lacks it. Satis
therefore adds a `published-time` to every version it builds:

- A version that was not in the previous build gets the time of the build.
- A tagged (non-dev) version keeps the time it was first published with, even
  if its tag is moved to another commit.
- A dev version gets the time of the build whenever its reference changes.
- A `published-time` provided by an upstream Composer repository, such as
  Packagist, is kept.

The previous build's metadata in the `p2` directory is the only record of these
times, so the `output-dir` must persist between builds. A version that is
missing from the previous build is published anew, and Composer then holds it
back for the cooldown period. This applies to every version in the first build
after upgrading to a Satis release that adds `published-time`, to every version
in a build into an empty directory, and to a version that is missing from a
single build while other versions of its package are built.

A package that is missing from a build entirely, for example because its
repository could not be loaded with `--skip-errors`, keeps the metadata of the
last build that contained it. When the package returns, its versions keep their
publication times instead of being held back again.

### Resolving dependencies

Satis can automatically resolve and add all dependencies of your packages.
Combined with [downloads](#downloads), this gives you a complete local mirror
of the packages. Add the following to your `satis.json`:

```json
{
    "require-dependencies": true,
    "require-dev-dependencies": true
}
```

Satis resolves the dependencies only from the repositories listed in the
configuration; Packagist is not used unless you list it. So if your packages
depend on packages from Packagist, add it to `repositories` (or to
[repositories-dep](config#repositories-dep)):

```json
{
    "repositories": [{ "type": "composer", "url": "https://repo.packagist.org" }]
}
```

Development dependencies are only added if `require-dev-dependencies` is set to
`true`.

### Other options

- `providers`: optional, `false` by default; when `true`, the metadata of each
  package is written to a separate provider file, which Composer only loads
  when it needs that package. This speeds up Composer for repositories with a
  large number of packages.
- `output-dir`: optional, the directory to write the repository files to when
  it is not passed as an argument to the `build` command.
- `config`: optional, any Composer configuration option, except
  `archive-format` and `archive-dir`, as archives are configured with
  [archive](#downloads) instead. See [config](config#config) for details.
- `notify-batch`: optional, a URL that Composer calls every time a user
  installs a package. See [notify-batch].
- `include-types`: optional, an array of [Composer package types]. When set,
  only packages with one of these types are included in the output.
- `exclude-types`: optional, an array of [Composer package types]. Packages
  with one of these types are left out of the output.

The type filters are applied after dependencies have been resolved, see
[include-types](config#include-types).

The `homepage` in the configuration can be overridden with the
`SATIS_HOMEPAGE` environment variable, for example to build the same
configuration for several hosts.

[ssh2 context options]: https://www.php.net/manual/en/wrappers.ssh2.php#refsect1-wrappers.ssh2-options
[ssl context options]: https://www.php.net/manual/en/context.ssl.php
[twig]: https://twig.symfony.com/
[COMPOSER_HOME]: https://getcomposer.org/doc/03-cli.md#composer-home
[notify-batch]: https://getcomposer.org/doc/05-repositories.md#notify-batch
[composer package types]: https://getcomposer.org/doc/04-schema.md#type
