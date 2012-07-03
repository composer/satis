Satis - Package Repository Generator
====================================

Simple static Composer repository generator.

It uses any composer.json file as input and dumps all the required (according
to their version constraints) packages to a Composer Repository file.

Usage
-----

- Download Composer: `wget http://getcomposer.org/composer.phar`
- Install satis: `php composer.phar create-project composer/satis`
- Build a repository: `php bin/satis build <composer.json> <build-dir>`

Read the more detailed instructions in the 
[documentation](http://getcomposer.org/doc/articles/handling-private-packages-with-satis.md).

Contributing
------------

All code contributions - including those of people having commit access -
must go through a pull request and approved by a core developer before being
merged. This is to ensure proper review of all the code.

Fork the project, create a feature branch, and send us a pull request.

Requirements
------------

PHP 5.3+

Authors
-------

Jordi Boggiano - <j.boggiano@seld.be> - <http://twitter.com/seldaek> - <http://seld.be><br />
Nils Adermann - <naderman@naderman.de> - <http://twitter.com/naderman> - <http://www.naderman.de><br />

See also the list of [contributors](https://github.com/composer/satis/contributors) who participated in this project.

License
-------

Satis is licensed under the MIT License - see the LICENSE file for details
