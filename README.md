# Satis

Simple static Composer repository generator.


## Run from source

Satis requires a recent PHP version, it does not run with unsupported PHP versions. Check the `composer.json` file for details.

- Install satis: `composer create-project composer/satis:dev-main`
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

- [satisfy][] - Symfony based composer repository manager with a simple web UI.


## Examples

- [eventum/composer] - A simple static set of packages hosted in GitHub Pages
- [satis.spatie.be] - A brief guide to setting up and securing a Satis repository


## License

Satis is licensed under the MIT License - see the [LICENSE][] file for details


[documentation]: https://getcomposer.org/doc/articles/handling-private-packages-with-satis.md
[Contributor Code of Conduct]: https://www.contributor-covenant.org/version/2/0/code_of_conduct/
[contributors]: https://github.com/composer/satis/contributors
[satisfy]: https://github.com/ludofleury/satisfy
[LICENSE]: https://github.com/composer/satis/blob/master/LICENSE
[eventum/composer]: https://github.com/eventum/composer
[satis.spatie.be]: https://alexvanderbist.com/2021/setting-up-and-securing-a-private-composer-repository/
