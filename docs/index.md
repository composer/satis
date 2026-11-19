---
layout: default
permalink: /
title: Introduction
---

Satis is an open-source [Composer](https://getcomposer.org) repository generator. It is like an ultra-lightweight,
static, file-based version of [Packagist](https://packagist.org) and can be used to host the metadata of your company's
private packages, or your own.
{: .lead}

## Installation

Install and run from source:

```sh
git clone https://github.com/composer/satis
cd satis
composer install --no-dev
bin/satis
```

Install using Composer:

```sh
composer create-project --keep-vcs --no-dev composer/satis:dev-main
cd satis
bin/satis
```

Run our Docker container, which builds `<workspace>/satis.json` into `<workspace>/output`:

```sh
docker pull composer/satis
docker run --rm -it -v <workspace>:/build composer/satis
```

Need access control per person, instant updates or security monitoring? See [Private Packagist](private-packagist).
