# Composer Patches Dev

[![Build Status](https://travis-ci.org/nathandentzau/composer-patches-dev.svg?branch=master)](https://travis-ci.org/nathandentzau/composer-patches-dev) [![Coverage Status](https://coveralls.io/repos/github/nathandentzau/composer-patches-dev/badge.svg?branch=%1B%5B32mmaster%1B%5Bm)](https://coveralls.io/github/nathandentzau/composer-patches-dev?branch=%1B%5B32mmaster%1B%5Bm)

This composer plugin extends the [cweagans/composer-patches][] plugin to allow
patches to be applied to packages when composer is in dev mode (without
`--no-dev` passed `composer install` or `composer update`). This is useful to
patch certain packages locally and not in a production environment.

## Installation

```bash
$ composer require nathandentzau/composer-patches-dev
```

## Usage

Add a `patches-dev` definition to either the `extras` array in `composer.json`
or in a specified [external patch file][].

### Applying patches defined in `patches-dev`

Run composer install or update without the `--no-dev` flag.

```bash
$ composer install
```
or
```bash
$ composer update
```

### Prevent applying patches defined in `patches-dev`

Run composer install or update with the `--no-dev` flag.

```bash
$ composer install --no-dev
```
or
```bash
$ composer update --no-dev
```

### Example: composer.json

```json
{
    "name": "nathandentzau/composer-patches-dev-test",
    "description": "A test project for nathandentzau/composer-patches-dev",
    "type": "project",
    "license": "MIT",
    "authors": [
        {
            "name": "Nathan Dentzau",
            "email": "nathan.dentzau@gmail.com"
        }
    ],
    "require": {
        "drupal/core": "8.5.0"
    },
    "require-dev": {
        "nathandentzau/composer-patches-dev": "^1.0"
    },
    "extra": {
        "patches-dev": {
            "drupal/core": {
                "Suppress filesystem errors with BindFS in Drupal core": "https://gist.githubusercontent.com/nathandentzau/355f3476b13cab38294ebc0207cedac0/raw/25c312a2814a7d62c812796c91099a091972b37c/suppress-filesystem-errors-with-bindfs-in-drupal-core.patch"
            }
        }
    }
}
```

## Error handling

Please refer to the [error handling documentation] for
[cweagans/composer-patches][].

## Why is this a seperate plugin?

The [cweagans/composer-patches][] plugin is on a feature freeze for its current
stable version. The next version is actively being worked on by its
maintainer. This feature will be merged into version 2.x of composer-patches
when the time is right. After that this package will be discontinued.

[cweagans/composer-patches]: https://github.com/cweagans/composer-patches
[external patch file]: https://github.com/cweagans/composer-patches/blob/master/README.md#using-an-external-patch-file
[error handling documentation]: https://github.com/cweagans/composer-patches/blob/master/README.md#error-handling
