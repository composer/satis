---
layout: default
permalink: /
title: Introduction
---

## Introduction

[![Source Code](//img.shields.io/badge/source-composer/satis-blue.svg?style=flat-square)](https://github.com/composer/satis)
[![License](//img.shields.io/packagist/l/composer/satis.svg?style=flat-square)](https://packagist.org/packages/composer/satis)
[![Build Status](//img.shields.io/travis/composer/satis/master.svg?style=flat-square)](https://travis-ci.org/composer/satis)

Satis is an open source <a href="https://getcomposer.org">Composer</a> repository generator. It is like an ultra-lightweight static file-based version of <a href="https://packagist.org">Packagist</a> and can be used to host the metadata of your company's private packages, or your own.

You can get it from GitHub:

    git clone https://github.com/composer/satis
    composer install

You can install it via CLI:

    composer create-project composer/satis --stability=dev

You can run it as a Docker container:

    docker pull composer/satis
    docker run --rm -it -v /build:/build composer/satis
