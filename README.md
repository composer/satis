Satis - Package Repository Generator
====================================

Simple static Composer repository generator.

It uses any composer.json file as input and dumps all the required (according
to their version constraints) packages into a Composer Repository file.

[![Build Status](https://travis-ci.org/composer/satis.svg?branch=master)](https://travis-ci.org/composer/satis)

Usage
-----

- Download [Composer](https://getcomposer.org/download/): `curl -sS https://getcomposer.org/installer | php`
- Install satis: `php composer.phar create-project composer/satis --stability=dev --keep-vcs`
- Build a repository: `php bin/satis build <configuration file> <build-dir>`

Read the more detailed instructions in the
[documentation](http://getcomposer.org/doc/articles/handling-private-packages-with-satis.md).

Purge
-----

If you choose to archive packages in your server, you can have useless files.  
With the `purge` command, you delete these files.

    php bin/satis purge <composer.json> <build-dir>

 > Note: be careful if you reference your archives in your lock file.

Updating
--------

Updating is as simple as running `git pull && php composer.phar install` in the satis directory.

Contributing
------------

Please note that this project is released with a [Contributor Code of Conduct](http://contributor-covenant.org/version/1/2/0/).
By participating in this project you agree to abide by its terms.

Fork the project, create a feature branch, and send us a pull request.

Requirements
------------

PHP 5.3+

Authors
-------

Jordi Boggiano - <j.boggiano@seld.be> - <http://twitter.com/seldaek> - <http://seld.be><br />
Nils Adermann - <naderman@naderman.de> - <http://twitter.com/naderman> - <http://www.naderman.de><br />

See also the list of [contributors](https://github.com/composer/satis/contributors) who participated in this project.

Community Tools
---------------
- [satis-go](https://github.com/benschw/satis-go) - A simple web server for managing Satis configuration and hosting the generated Composer repository.
- [satisfy](https://github.com/ludofleury/satisfy) - Symfony based composer repository manager with a simple web UI.
- [satis-control-panel](https://github.com/realshadow/satis-control-panel) - Simple web UI for managing your Satis Repository with optional CI integration.


License
-------

Satis is licensed under the MIT License - see the LICENSE file for details
