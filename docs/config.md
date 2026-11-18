---
layout: default
permalink: config
title: Config
---

# Config

## Example

```
{
  "name": "acme/satis",
  "description": "A descriptive line of text goes here.",
  "homepage": "http://packages.example.org",
  "repositories": [{
    "type": "composer",
    "url": "https://packagist.org"
  }],
  "repositories-dep": [],
  "require": {
    "company/package1": "1.2.0",
    "company/package2": "^1.5.2",
    "company/package3": "dev-master|dev-develop"
  },
  "archive": {
    "directory": "dist",
    "format": "tar",
    "skip-dev": true,
    "absolute-directory": "/path",
    "prefix-url": "https://amazing.cdn.example.org",
    "whitelist": [ "company/package1" ],
    "blacklist": [ "company/package2" ],
    "checksum": true,
    "ignore-filters": false,
    "override-dist-type": true,
    "rearchive": true
  },
  "abandoned": {
    "company/package": true,
    "company/package2": "company/newpackage"
  },
  "require-all": false,
  "require-dependencies": true,
  "require-dev-dependencies": true,
  "providers": false,
  "providers-history-size": 0,
  "output-dir": "output",
  "output-html": true,
  "twig-template": "views/index.html.twig",
  "config": {

  },
  "strip-hosts": [],
  "notify-batch": "https://example.com/endpoint"
}
```

## Keys

### name

The name of the Satis repository. Available inside the template as `{% raw %}{{ name }}{% endraw %}`.

Satis loads its configuration as a Composer root package, so the name must be a valid package name: a vendor name, a
forward slash and a package name, in lowercase, for example `acme/satis`. A name such as "My Satis Repository" makes the
build fail with "Your package name ... is invalid".

### description

A brief description of the Satis repository. Available inside the template as `{% raw %}{{ description }}{% endraw %}`

### homepage

Available inside the template as `{% raw %}{{ url }}{% endraw %}`.

### repositories

The repositories to read packages from, in the same format as the `repositories` key of `composer.json`. Any repository
type Composer supports can be used, such as `vcs`, `composer`, `artifact`, `path` or `package`. Packagist is not used
unless it is listed here.

Giving a VCS repository a `name` lets partial builds skip it, see [Partial Updates](using#partial-updates).

### repositories-dep

Additional repositories, in the same format as `repositories`, that are only used to resolve dependencies when
"require-dependencies" or "require-dev-dependencies" is enabled. Packages are taken from them only when a selected
package depends on them; "require" and "require-all" do not select from these repositories.

For example, to include all of your private packages plus the packages from Packagist they depend on:

```
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/mycompany/privaterepo" }
  ],
  "repositories-dep": [
    { "type": "composer", "url": "https://repo.packagist.org" }
  ],
  "require-all": true,
  "require-dependencies": true
}
```

### require

Hash of package name (keys) and version constraint (values) that should be included in the output.

### minimum-stability

The lowest stability a version must have to be selected: `dev` (the default), `alpha`, `beta`, `RC` or `stable`. It
applies to packages selected through "require" and "require-all" and to their dependencies. For example, `"stable"`
leaves out branches and alpha, beta and RC releases.

### minimum-stability-per-package

Hash of package name (keys) and minimum stability (values) that overrides "minimum-stability" for these packages.

```
{
  "minimum-stability": "stable",
  "minimum-stability-per-package": {
    "company/package": "dev"
  }
}
```

With "require-all", packages from repositories other than `composer` repositories are only filtered by
"minimum-stability".

### archive

Configuration for creating package archives.

directory
: The directory in which to output the archives.

format
: The archive format to use, `zip` (the default) or `tar`. Tar archives are created with PHP's `PharData`, which cannot store file names longer than 100 characters, so archiving a package that contains such a file fails. Use `zip`, or set **rearchive** to false to serve the original archive of packages that have a zip or tar dist.

skip-dev
: Whether or not to create archives for development versions.

absolute-directory
: The directory in which to output the archives (prioritized over **directory** if provided).

prefix-url
: Hostname (and path) to prefix when generating source url for the archive (defaults to **homepage**).

whitelist
: List of whitelisted packages (only matching packages will be output). A `*` can be used for wildcard matching.

blacklist
: List of blacklisted packages (matching packages will be skipped). A `*` can be used for wildcard matching.

checksum
: Whether or not to generate checksum values.

ignore-filters
: Whether or not to ignore filters when looking for files in packages.

override-dist-type
: If true, archive format will be used to substitute the original dist type.

rearchive
: If true, rearchive packages with a tar or zip dist.

### abandoned

A list of packages that will visually be marked as abandoned. Optionally a replacement can be suggested.

### require-all

If true, selects all versions of all packages in all repositories defined.

### require-dependencies

If true, resolve and add all dependencies of each required package.

### require-dev-dependencies

If true, resolve and add all development dependencies of each required package.

### only-dependencies

If true, will only resolve and add dependencies, not the root projects listed in "require".

### only-best-candidates

If true, only the best matching version is selected for each dependency constraint, instead of every version that
matches it. Packages selected through "require" or "require-all" are not affected.

Each constraint is resolved on its own, without running Composer's solver over the selected packages. Packages that
constrain each other, for example with `self.version`, can therefore end up in versions that cannot be installed
together.

If `config.platform` is set, candidates whose platform requirements (such as `php`) do not match it are skipped, so the
selected versions can be installed on that platform. As in Composer, platform packages you don't list in
`config.platform` are checked against the machine running Satis, so also pin the extensions your packages need (e.g.
`"ext-intl": "8.3.0"`) if that machine doesn't have them. Without `config.platform`, platform requirements are ignored
when picking candidates.

### filter-tags-by-require

If true, tags of VCS repositories that do not match the version constraint in "require" are skipped without reading their `composer.json`, which speeds up builds of repositories with many tags. Branches are always read. Tags are only skipped if the `composer.json` of the default branch declares the package, as the tags may contain other packages otherwise. Tags outside the constraint are still read until one of them loads, so the repository provides the package as without this option, which keeps lower-priority repositories from supplying it. Defaults to false.

This only applies to repositories that set `name` to a package listed in "require". It has no effect if "require-all", "require-dependencies" or "require-dev-dependencies" is enabled, on repositories using `only`, `exclude` or `canonical`, or on packages required with an inline alias.

```
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/acme/library", "name": "acme/library" }
  ],
  "require": {
    "acme/library": "^2.0"
  },
  "filter-tags-by-require": true
}
```

### blacklist

Define a list of packages and versions to suppress in the final packages list. Takes the same format as the `require` section.

### include-types

List of [package types](https://getcomposer.org/doc/04-schema.md#type). If set, only packages with one of these types are
included in the output.

### exclude-types

List of [package types](https://getcomposer.org/doc/04-schema.md#type). Packages with one of these types are left out of
the output.

Both filters are applied after dependencies have been resolved, so the dependencies of a package that is left out are
still included.

### require-dependency-filter

If false, will include versions matching a dependency.

### strip-hosts

List of hosts whose URLs are removed from the `source` and `dist` entries of the packages in the output. Each entry can
be:

- a host name, which also matches its subdomains (`example.org` matches `git.example.org`),
- an IPv4 or IPv6 address, optionally with a CIDR mask (`10.0.0.0/8`),
- `/local`, which matches `localhost` and reserved IP addresses,
- `/private`, which matches private IP addresses.

For SSH URLs like `git@example.org:acme/package.git` the host is matched. If the option is set to anything but false,
including `true` or an empty list, URLs that are local file paths are removed as well. Dist URLs that point to archives
built by Satis (see [archive](#archive)) are kept. A package that has neither a source nor a dist left is removed from
the output.

Together with [archive](#archive), this can publish packages with only a dist URL. Composer clients then cannot fall
back to installing from the VCS host, for example when they are not allowed to download the archive:

```
{
  "archive": {
    "directory": "dist"
  },
  "strip-hosts": ["github.com"]
}
```

Versions that Satis does not archive (see **skip-dev**, **whitelist** and **blacklist** under [archive](#archive)) keep
their original URLs, so they are removed if both their source and their dist are on a stripped host.

### include-filename

Filename to use for the json to include, defaults to `include/all${SHA1_HASH}.json`.

### output-dir

Directory in which to store repository data.

### output-html

If true, generate html output from templates.

### twig-template

Path to twig template used for generating html output.

### allow-seo-indexing

If true, the html output does not tell search engines to skip it (it leaves out the `noindex,nofollow` robots meta tag).
Defaults to false.

### providers

If true, output package providers. This will generate a directory per vendor and a json file per package.

### providers-history-size

The number of outdated include and provider files to keep. These files have a hash of their contents in their name, and
a build deletes the ones it no longer references. With a value of `n`, the `n` most recent outdated files of each kind
are kept, so clients with an older `packages.json` can still download the files it refers to. Defaults to 0.

### includes

If true, output package includes. This is `true` by default - setting it to `false` allows you to work with Composer v2
metadata URLs only.

### available-package-patterns

List of package name patterns, like `vendor/*`, that is written to `packages.json` as `available-package-patterns`
instead of the list of all package names (`available-packages`). Composer 2 only looks for packages in the repository
whose name matches one of the patterns, so they must cover every package in the output. This keeps `packages.json`
small for repositories with many packages.

### pretty-print

Whether or not to use `JSON_PRETTY_PRINT` when generating json output.

### config

Composer [configuration options](https://getcomposer.org/doc/06-config.md), as in the `config` section of
`composer.json`. Satis uses Composer to read the repositories and to create the archives, and passes these options on to
it. `archive-format` and `archive-dir` are not used, archives are configured with [archive](#archive).

Options that are often useful:

- `secure-http`: set to false to allow repositories over insecure protocols such as `http://` or `svn://`.
- `gitlab-protocol`: the protocol for the source URLs of GitLab repositories, `git` (SSH) or `http`.
- `gitlab-domains` and `github-domains`: the domains of self-hosted GitLab or GitHub Enterprise instances.
- `gitlab-token`, `github-oauth` and `http-basic`: credentials for the repositories. These are better kept in the
  `auth.json` file in `COMPOSER_HOME` than in the Satis configuration, see [Authentication](using#authentication).
- `vendor-dir`: Composer extracts downloaded dist archives into `<vendor-dir>/composer/` when Satis creates its archives.
  If it is not set, Satis uses a temporary directory and removes it after the build.

`platform` is only used by [only-best-candidates](#only-best-candidates); otherwise Satis skips platform requirements
such as `php` and `ext-*` when it selects packages.

### notify-batch

A URL that is written to `packages.json` as `notify-batch`. Composer calls it after installing packages from the
repository, see [notify-batch](https://getcomposer.org/doc/05-repositories.md#notify-batch).
