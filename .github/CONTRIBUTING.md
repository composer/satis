# Contributing to Satis

:+1::tada: First off, thanks for taking the time to contribute! :tada::+1:

Please note that this project is released with a [Contributor Code of Conduct](CODE_OF_CONDUCT.md). By participating
in this project you agree to abide by its terms.

## How to contribute

1. Fork the project.
2. Create a feature branch.
3. Send us a pull request.

If you add a feature or fix a bug, please include a test case.

## Tests and coding standards

Run the tests with:

```sh
composer install
composer test
```

Contributions should ideally meet our coding standards, which CI checks. You can run the same checks yourself:

```sh
# install the tools
for d in tools/*; do composer --working-dir=$d install; done

# check the code style, or fix it
composer php-cs-fixer
composer php-cs-fixer-fix

# run static analysis
composer phpstan

# lint the JavaScript and the other assets
npm ci
npm run eslint:ci
npm run prettier:ci
```
