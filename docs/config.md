---
layout: default
permalink: config
title: Config
---

# Config

## Example

```json
{
    "name": "acme/satis",
    "description": "A descriptive line of text goes here.",
    "homepage": "https://packages.example.org",
    "repositories": [
        {
            "type": "composer",
            "url": "https://packagist.org"
        }
    ],
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
        "whitelist": ["company/package1"],
        "blacklist": ["company/package2"],
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
    "config": {},
    "notify-batch": "https://example.com/endpoint"
}
```

## Keys

### name

Required. The name of the Satis repository. Available inside the template as `{% raw %}{{ name }}{% endraw %}`.

Satis loads its configuration as a Composer root package, so the name must be a valid package name: a vendor name, a
forward slash and a package name, in lowercase, for example `acme/satis`. A name such as "My Satis Repository" makes the
build fail with "Your package name ... is invalid".

### description

A brief description of the Satis repository. Available inside the template as `{% raw %}{{ description }}{% endraw %}`.

### homepage

Required. The URL the repository is served from. Its path is the base of the provider and metadata URLs in
`packages.json`, and the URL is the default **prefix-url** of [archive](#archive). Available inside the template as
`{% raw %}{{ url }}{% endraw %}`. The `SATIS_HOMEPAGE` environment variable overrides it.

### repositories

The repositories to read packages from, in the same format as the `repositories` key of `composer.json`. Any repository
type Composer supports can be used, such as `vcs`, `composer`, `artifact`, `path` or `package`. Packagist is not used
unless it is listed here.

A partial build (one given package names on the command line) only scans the remote repositories whose `name` matches
one of the requested packages, so give each VCS repository the name of the package it holds, see
[Partial updates](using#partial-updates).

### repositories-dep

Additional repositories, in the same format as `repositories`, that are only used to resolve dependencies when
`require-dependencies` or `require-dev-dependencies` is enabled. Packages are taken from them only when a selected
package depends on them; `require` and `require-all` do not select from these repositories.

For example, to include all of your private packages plus the packages from Packagist they depend on:

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/mycompany/privaterepo" }],
    "repositories-dep": [{ "type": "composer", "url": "https://repo.packagist.org" }],
    "require-all": true,
    "require-dependencies": true
}
```

### require

Map of package names (keys) to version constraints (values) to include in the output. If `require` is left out, Satis
enables `require-all`.

### minimum-stability

The lowest stability a version must have to be selected: `dev` (the default), `alpha`, `beta`, `RC` or `stable`. It
applies to packages selected through `require` and `require-all` and to their dependencies. For example, `"stable"`
leaves out branches and alpha, beta and RC releases.

### minimum-stability-per-package

Map of package names (keys) to minimum stabilities (values) that override `minimum-stability` for these packages.

```json
{
    "minimum-stability": "stable",
    "minimum-stability-per-package": {
        "company/package": "dev"
    }
}
```

With `require-all`, packages from repositories other than `composer` repositories are only filtered by
`minimum-stability`.

### archive

Configuration for creating package archives, see [Downloads](using#downloads).

directory
: Required. The directory to write the archives to, relative to the output directory. It is also part of the download URLs.

format
: The archive format to use, `zip` (the default) or `tar`. Tar archives are created with PHP's `PharData`, which cannot store a file whose name (the last part of its path) is longer than 100 characters, so archiving a package that contains such a file fails. Use `zip`, or set **rearchive** to false to serve the original archive of packages that have a zip or tar dist.

skip-dev
: Whether to skip creating archives for development versions (branches). Defaults to false.

absolute-directory
: A local directory to write the archives to instead of **directory** inside the output directory. The download URLs still use **directory**, so serve this directory at `<prefix-url>/<directory>`.

prefix-url
: The base URL of the archive downloads, followed by **directory** (defaults to **homepage**).

whitelist
: List of packages to archive; other packages keep their original download URLs. A `*` can be used for wildcard matching.

blacklist
: List of packages not to archive; they keep their original download URLs. A `*` can be used for wildcard matching.

checksum
: Whether to publish the SHA-1 checksum of the archives. Defaults to true.

ignore-filters
: Whether to ignore the package's own archive exclusions (its `archive.exclude` and `export-ignore` attributes), so the archives contain all of its files. Defaults to false.

override-dist-type
: If true, the archive file names contain the archive **format** instead of the package's original dist type. Defaults to false.

rearchive
: If true, packages that have a tar or zip dist are archived again; if false, that dist is served as it is and **format** does not apply to it. Defaults to true.

### abandoned

Map of package names to `true` or to the name of a replacement package. These packages are marked abandoned in the
metadata, so Composer warns when it installs them, and in the web page.

### require-all

If true, selects all versions of all packages in all defined repositories.

### require-dependencies

If true, resolve and add all dependencies of each selected package.

### require-dev-dependencies

If true, resolve and add the development dependencies of the packages selected through `require` or `require-all`. To
also follow the development dependencies of their dependencies, set
[require-dependency-filter](#require-dependency-filter) to false.

### only-dependencies

If true, only the dependencies are resolved and added, not the packages selected through `require` or `require-all`
themselves.

### only-best-candidates

If true, only the best matching version is selected for each dependency constraint, instead of every version that
matches it. Packages selected through `require` or `require-all` are not affected.

Each constraint is resolved on its own, without running Composer's solver over the selected packages. Packages that
constrain each other, for example with `self.version`, can therefore end up in versions that cannot be installed
together.

If `config.platform` is set, candidates whose platform requirements (such as `php`) do not match it are skipped, so the
selected versions can be installed on that platform. As in Composer, platform packages you don't list in
`config.platform` are checked against the machine running Satis, so also pin the extensions your packages need (e.g.
`"ext-intl": "8.3.0"`) if that machine doesn't have them. Without `config.platform`, platform requirements are ignored
when picking candidates.

### filter-tags-by-require

If true, tags of VCS repositories that do not match the version constraint in `require` are skipped without reading
their `composer.json`, which speeds up builds of repositories with many tags. Defaults to false.

Branches are always read. Tags are only skipped if the `composer.json` of the default branch declares the package, as
the tags may contain other packages otherwise. Satis still reads tags outside the constraint until one of them loads.
This way the repository provides the package just as it would without this option, so lower-priority repositories
cannot supply it instead.

This only applies to repositories that set `name` to a package listed in `require`. It has no effect when
`require-all`, `require-dependencies` or `require-dev-dependencies` is enabled. It also does not apply to repositories
that use `only`, `exclude` or `canonical`, or to packages required with an inline alias.

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/acme/library", "name": "acme/library" }],
    "require": {
        "acme/library": "^2.0"
    },
    "filter-tags-by-require": true
}
```

### blacklist

Packages and versions to leave out of the output, in the same format as `require`. Unlike the type filters below, the
blacklist is applied while packages are selected, so the dependencies of a blacklisted version are not pulled in
through it. With `only-best-candidates`, the best version that is not blacklisted is selected instead.

### include-types

List of [package types](https://getcomposer.org/doc/04-schema.md#type). If set, only packages with one of these types
are included in the output.

### exclude-types

List of [package types](https://getcomposer.org/doc/04-schema.md#type). Packages with one of these types are left out of
the output.

Both filters are applied after dependencies have been resolved, so the dependencies of a package that is left out are
still included.

### require-dependency-filter

Defaults to true, in which case `require-dev-dependencies` only follows the development dependencies of the packages
selected through `require` or `require-all`. If false, it also follows the development dependencies of their
dependencies.

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

```json
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

The file name of the include file, defaults to `include/all$%hash%.json`. `%hash%` is replaced with the SHA-1 hash of
the contents. It must be in the file name, not in the directory, for outdated include files to be deleted (see
[providers-history-size](#providers-history-size)).

### output-dir

The directory to write the repository to. The output directory given on the command line takes precedence.

### output-html

If true, generate the HTML page from the template. Defaults to true.

### twig-template

Path to the Twig template used to generate the HTML page.

Templates can use the `browser_dist_url` filter to turn GitHub API dist URLs, which a browser cannot download for
private repositories, into archive URLs that work with a GitHub session: `{% raw %}{{ version.distUrl|browser_dist_url }}{% endraw %}`.

### allow-seo-indexing

If true, the HTML page does not tell search engines to skip it (it leaves out the `noindex,nofollow` robots meta tag).
Defaults to false.

### providers

If true, output package providers: a JSON file per package, in a directory per vendor under `p/`. Defaults to false.

### providers-history-size

The number of outdated include and provider files to keep. These files have a hash of their contents in their name, and
a build deletes the ones it no longer references. With a value of `n`, the `n` most recent outdated files of each
include file and of each package's provider file are kept, so clients with an older `packages.json` can still download
the files it refers to. Defaults to 0.

### includes

If true, output package includes. Defaults to true. Set it to false to serve only Composer 2 metadata URLs.

### available-package-patterns

List of package name patterns, like `vendor/*`, that is written to `packages.json` as `available-package-patterns`
instead of the list of all package names (`available-packages`). Composer 2 only looks for packages in the repository
whose name matches one of the patterns, so they must cover every package in the output. This keeps `packages.json`
small for repositories with many packages.

### pretty-print

Whether to use `JSON_PRETTY_PRINT` when writing the include, provider and `p2/` metadata files. Defaults to true.
`packages.json` itself is always pretty-printed.

### config

Composer [configuration options](https://getcomposer.org/doc/06-config.md), as in the `config` section of
`composer.json`. Satis uses Composer to read the repositories and to create the archives, and passes these options on to
it. `archive-format` and `archive-dir` are not used; archives are configured with [archive](#archive).

Options that are often useful:

- `secure-http`: set to false to allow repositories over insecure protocols such as `http://` or `svn://`.
- `gitlab-protocol`: the protocol for the source URLs of GitLab repositories, `git` (SSH) or `http`.
- `gitlab-domains` and `github-domains`: the domains of self-hosted GitLab or GitHub Enterprise instances.
- `gitlab-token`, `github-oauth` and `http-basic`: credentials for the repositories. These are better kept in an
  `auth.json` file, next to the Satis configuration or in `COMPOSER_HOME`, than in the Satis configuration, see
  [Authentication](using#authentication).
- `vendor-dir`: Composer extracts downloaded dist archives into `<vendor-dir>/composer/` when Satis creates its archives.
  If it is not set, Satis uses a temporary directory and removes it after the build. When Satis runs as a Composer
  plugin, the project's own `vendor-dir` is used.

`platform` is only used by [only-best-candidates](#only-best-candidates); otherwise Satis skips platform requirements
such as `php` and `ext-*` when it selects packages.

### notify-batch

A URL that is written to `packages.json` as `notify-batch`. Composer calls it after installing packages from the
repository, see [notify-batch](https://getcomposer.org/doc/05-repositories.md#notify-batch).
