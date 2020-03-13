## hcesrl/gitlab-composer

[![License](http://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](https://tldrlegal.com/license/mit-license)

A [Composer repository](https://getcomposer.org/doc/05-repositories.md#composer) index generator for your GitLab CE projects.

## Installation

Install the package:
```bash
composer require hcesrl/gitlab-composer
```

## Usage
Create a new instance of the `Packages` object with the GitLab CE Api endpoint and the access token:
```php
$packages = new \GitLabComposer\Packages( 'https://gitlab.example.com/api/v4/', 'some_access_token' );
```

Customize the behaviour by setting a path for the cache files and a whitelist of groups and projects:
```php
$packages->setCachePath ( __DIR__ . '/../cache' );

$packages->addGroup ( 'group1', 'group2' );

$packages->addProject ( 'group1/foo', 'group2/bar', 'group2/foobar' );
```

Render the packages json file:
```php
$packages->render();
```

## License
This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).

## Authors
*  [HCE](https://www.hce.it/)
