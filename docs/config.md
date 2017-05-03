---
layout: default
permalink: config
title: Config
---

## Config

```
{
  "name": "My Satis Repository",
  "description": "A descriptive line of text goes here.",
  "homepage": "http://packages.example.org",
  "repositories": [{
    "type": "composer",
    "url": "https://packagist.org"
  }],
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
    "checksum": true
  },
  "abandoned": {
    "company/package": true,
    "company/package2": "company/newpackage"
  },
  "require-all": false,
  "require-dependencies": true,
  "require-dev-dependencies": true,
  "providers": false,
  "output-dir": "output",
  "output-html": true,
  "twig-template": "views/index.html.twig",
  "config": {

  },
  "notify-batch": "https://example.com/endpoint"
}
```
