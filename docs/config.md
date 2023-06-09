---
layout: default
permalink: config
title: Config
---

# Config

## Example

```
{
  "name": "My Satis Repository",
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

### description

A brief description of the Satis repository. Available inside the template as `{% raw %}{{ description }}{% endraw %}`

### homepage

Available inside the template as `{% raw %}{{ url }}{% endraw %}`.

### require

Hash of package name (keys) and version constraint (values) that should be included in the output.

### archive

Configuration for creating package archives.

directory
: The directory in which to output the archives.

format
: The archive format to use.

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

Returns a minimal set of dependencies needed to satisfy the configuration. The resulting satis repository will contain only one or two versions of each project.

### blacklist

Define a list of packages and versions to suppress in the final packages list. Takes the same format as the `require` section.

### require-dependency-filter

If false, will include versions matching a dependency.

### include-filename

Filename to use for the json to include, defaults to `include/all${SHA1_HASH}.json`.

### output-dir

Directory in which to store repository data.

### output-html

If true, generate html output from templates.

### twig-template

Path to twig template used for generating html output.

### providers

If true, output package providers. This will generate a directory per vendor and a json file per package.

### includes

If true, output package includes. This is `true` by default - setting it to `false` allows you to work with Composer v2
metadata URLs only.

### pretty-print

Whether or not to use `JSON_PRETTY_PRINT` when generating json output.
