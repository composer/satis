# Satis

Simple static Composer repository generator.

[![Build Status](https://travis-ci.org/composer/satis.svg?branch=master)](https://travis-ci.org/composer/satis)
[![codecov](https://codecov.io/gh/composer/satis/branch/master/graph/badge.svg)](https://codecov.io/gh/composer/satis)


## Run from source

Satis requires a recent PHP version, it does not run with unsupported PHP versions. Check the `composer.json` file for details.

- Install satis: `composer create-project composer/satis:dev-master`
- Build a repository: `php bin/satis build <configuration-file> <output-directory>`

Read the more detailed instructions in the [documentation][].


## Run as Docker container

Pull the image:

``` sh
docker pull composer/satis
```

Run the image (with Composer cache from host):

``` sh
docker run --rm --init -it \
  --user $(id -u):$(id -g) \
  --volume $(pwd):/build \
  --volume "${COMPOSER_HOME:-$HOME/.composer}:/composer" \
  composer/satis build <configuration-file> <output-directory>
```

If you want to run the image without implicitly running Satis, you have to
override the entrypoint specified in the `Dockerfile`:

``` sh
--entrypoint /bin/sh
```


## Purge

If you choose to archive packages as part of your build, over time you can be
left with useless files. With the `purge` command, you can delete these files.

``` sh
php bin/satis purge <configuration-file> <output-dir>
```

 > Note: don't do this unless you are certain your projects no longer reference
    any of these archives in their `composer.lock` files.


## Updating

Updating Satis is as simple as running `git pull && composer install` in the
Satis directory.

If you are running Satis as a Docker container, simply pull the latest image.


## Contributing

Please note that this project is released with a [Contributor Code of Conduct][].
By participating in this project you agree to abide by its terms.

Fork the project, create a feature branch, and send us a pull request.

If you introduce a new feature, or fix a bug, please try to include a testcase.


## Authors

See the list of [contributors][] who participate(d) in this project.


## Community Tools

- [satis-go][] - A simple web server for managing Satis configuration and
    hosting the generated Composer repository.
- [satisfy][] - Symfony based composer repository manager with a simple web UI.
- [satis-control-panel][] - Simple web UI for managing your Satis Repository
    with optional CI integration.
- [composer-satis-builder][] - Simple tool for updating the Satis configuration
    (satis.json) "require" key on the basis of the project composer.json.


## Examples

- [eventum/composer] - A simple static set of packages hosted in GitHub Pages


## License

Satis is licensed under the MIT License - see the [LICENSE][] file for details


[documentation]: https://getcomposer.org/doc/articles/handling-private-packages-with-satis.md
[Contributor Code of Conduct]: http://contributor-covenant.org/version/1/4/
[contributors]: https://github.com/composer/satis/contributors
[satis-go]: https://github.com/benschw/satis-go
[satisfy]: https://github.com/ludofleury/satisfy
[satis-control-panel]: https://github.com/realshadow/satis-control-panel
[composer-satis-builder]: https://github.com/AOEpeople/composer-satis-builder
[LICENSE]: https://github.com/composer/satis/blob/master/LICENSE
[eventum/composer]: https://github.com/eventum/composer
