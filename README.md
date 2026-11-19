# Satis

A simple static Composer repository generator.

## About

Satis lets PHP developers create a private package repository for their projects' dependencies. It generates a static
Composer repository that can be built locally or in a Docker container and hosted on any static web server, which gives you
more control over package distribution, better security and faster installs.

## Run from source

Satis requires a supported PHP version; see the `composer.json` file for details.

- Install Satis: `composer create-project --keep-vcs --no-dev composer/satis:dev-main`
- Build a repository: `php bin/satis build <configuration-file> <output-dir>`

Read the more detailed instructions in the [documentation][].

## Run as Docker container

> Note: use `composer/satis` for Docker Hub, `ghcr.io/composer/satis` for GitHub Container Registry.

Pull the image:

```sh
docker pull composer/satis
```

Run the image (with Composer cache from host):

```sh
docker run --rm --init -it \
  --user $(id -u):$(id -g) \
  --volume $(pwd):/build \
  --volume "${COMPOSER_HOME:-$HOME/.composer}:/composer" \
  composer/satis build <configuration-file> <output-dir>
```

To clone repositories over SSH, forward your SSH agent into the container and
mount a `known_hosts` file that contains the keys of your Git hosts:

```sh
docker run --rm --init -it \
  --user $(id -u):$(id -g) \
  --volume $(pwd):/build \
  --volume "${COMPOSER_HOME:-$HOME/.composer}:/composer" \
  --volume "$SSH_AUTH_SOCK:/ssh-agent" \
  --env SSH_AUTH_SOCK=/ssh-agent \
  --volume "$HOME/.ssh/known_hosts:/etc/ssh/ssh_known_hosts:ro" \
  composer/satis build <configuration-file> <output-dir>
```

These examples mount `$COMPOSER_HOME`, or `~/.composer` if it is not set. If
your Composer home is elsewhere, for example `~/.config/composer` (run
`composer config --global home` to see where it is), mount that directory
instead.

Arguments that are not a Satis command or option are run as a command in the
container, so to open a shell instead of running Satis:

```sh
docker run --rm -it composer/satis sh
```

## Purge

If you archive packages as part of your build, archives that are no longer used
pile up over time. The `purge` command deletes them.

```sh
php bin/satis purge <configuration-file> <output-dir>
```

> Note: don't do this unless you are certain your projects no longer reference any of these archives in their `composer.lock` files.

See [Purge](https://composer.github.io/satis/using#purge) in the documentation for a dry run and other caveats.

## Updating

To update Satis, run `git pull && composer install --no-dev` in the Satis
directory.

If you are running Satis as a Docker container, simply pull the latest image.

## Contributing

Please note that this project is released with a [Contributor Code of Conduct][].
By participating in this project you agree to abide by its terms.

Fork the project, create a feature branch, and send us a pull request. See
[CONTRIBUTING.md][contributing] for how to run the tests and the code style checks.

## Authors

See the list of [contributors][] who have contributed to this project.

## Community tools

- [satisfy][] - Symfony-based Composer repository manager with a simple web UI.

## Examples

- [eventum/composer] - A simple static set of packages hosted on GitHub Pages.
- [satis.spatie.be] - A brief guide to setting up and securing a Satis repository.

## License

Satis is licensed under the MIT License; see the [LICENSE][] file for details.

[documentation]: https://composer.github.io/satis/
[contributor code of conduct]: .github/CODE_OF_CONDUCT.md
[contributing]: .github/CONTRIBUTING.md
[contributors]: https://github.com/composer/satis/contributors
[satisfy]: https://github.com/project-satisfy/satisfy
[license]: https://github.com/composer/satis/blob/main/LICENSE
[eventum/composer]: https://github.com/eventum/composer
[satis.spatie.be]: https://alexvanderbist.com/2021/setting-up-and-securing-a-private-composer-repository/
