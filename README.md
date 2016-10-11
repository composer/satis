Satis - Package Repository Generator
====================================

Simple static Composer repository generator.

It uses any composer.json file as input and dumps all the required (according
to their version constraints) packages into a Composer Repository file.

[![Build Status](https://travis-ci.org/composer/satis.svg?branch=master)](https://travis-ci.org/composer/satis)

Usage
-----

- Install satis: `composer create-project composer/satis:dev-master --keep-vcs`
- Build a repository: `php bin/satis build <configuration file> <build-dir>`

Read the more detailed instructions in the [documentation][].

Docker
------

Build and tag the image:

``` sh
docker build -t composer/satis:latest .
```

Run the image:
 
``` sh
docker run --rm -it -v /build:/build composer/satis
```

 > Note: by default it will look for a `satis.json` inside the `/build`
    directory and output the templates inside `/build/output`.

 > Note: you can use your host's Composer cache by additionally mounting the
    Composer home directory using `-v $COMPOSER_HOME:/composer`.

If you want to run the image without implicitly running Satis, you have to
override the entrypoint specified in the `Dockerfile`:

``` sh
docker run --rm -it --entrypoint /bin/bash composer/satis
```

Purge
-----

If you choose to archive packages as part of your build, over time you can be
left with useless files. With the `purge` command, you can delete these files.

    php bin/satis purge <satis.json> <build-dir>

 > Note: be careful if you reference your archives in your lock file.

Updating
--------

Updating is as simple as running `git pull && composer update` in the
satis directory.

Contributing
------------

Please note that this project is released with a [Contributor Code of Conduct][].
By participating in this project you agree to abide by its terms.

Fork the project, create a feature branch, and send us a pull request.

Requirements
------------

PHP 5.5+

Authors
-------

Jordi Boggiano - <j.boggiano@seld.be> - <http://twitter.com/seldaek> - <http://seld.be><br />
Nils Adermann - <naderman@naderman.de> - <http://twitter.com/naderman> - <http://www.naderman.de><br />

See also the list of [contributors][] who participated in this project.

Community Tools
---------------
- [satis-go][] - A simple web server for managing Satis configuration and hosting the generated Composer repository.
- [satisfy][] - Symfony based composer repository manager with a simple web UI.
- [satis-control-panel][] - Simple web UI for managing your Satis Repository with optional CI integration.
- [composer-satis-builder][] - Simple tool for updating the Satis configuration (satis.json) "require" key on the basis of the project composer.json.

License
-------

Satis is licensed under the MIT License - see the LICENSE file for details


[documentation]: https://getcomposer.org/doc/articles/handling-private-packages-with-satis.md
[Contributor Code of Conduct]: http://contributor-covenant.org/version/1/4/
[contributors]: https://github.com/composer/satis/contributors
[satis-go]: https://github.com/benschw/satis-go
[satisfy]: https://github.com/ludofleury/satisfy
[satis-control-panel]: https://github.com/realshadow/satis-control-panel
[composer-satis-builder]: https://github.com/AOEpeople/composer-satis-builder
