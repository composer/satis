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
    "company/package2": "1.5.2",
    "company/package3": "dev-master"
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

The name of the Satis repository. Available inside the template as {% raw %}{{ name }}{% endraw %}.

### description

A brief description of the Satis repository. Available inside the template as `{{ description }}`

### homepage

Available inside the template `{{ url }}`.

### require

Hash of package name (keys) and version constraint (values) that should be included in the output.

### archive

Configuration for creating package archives.

#### archive.directory

Directory in which archives should be stored.