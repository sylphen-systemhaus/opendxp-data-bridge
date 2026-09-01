# Blackbit Data Director Bundle

> **Hinweis:** Original-README von Blackbit Data Director 3.10.4 (Pimcore).  
> Für Sylphen/OpenDXP siehe [README.md](README.md). Befehle mit `pimcore`/`BlackbitDataDirectorBundle`/`blackbit/data-director` gelten hier nicht 1:1.

Import XML, CSV, JSON, Excel files to Pimcore objects, assets, documents + Export feeds + create REST API without any programming

For an overview how to use this plugin, please see our [tutorial videos](https://www.youtube.com/playlist?list=PL4-QRNfdsdKIfzQIP-c9hRruXf0r48fjt).

## Installation

### Composer

To get the plugin code you have to [buy the plugin](https://shop.blackbit.com/pimcore-data-director/) or write an email to [info@blackbit.de](mailto:info@blackbit.de).

You then either get access to the bundle's [Bitbucket repository](https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director) or you get the plugin code as a zip file. Accessing the Bitbucket repository has the advantage that you will always see changes to the plugin in the pull requests and are able to update to a new version yourself - please visit [this page](https://shop.blackbit.com/bitbucket-access-to-blackbit-plugin-development/) if this sounds interesting to you - if it does, please send us the email address of your BitBucket account so we can allow access to the repository.

When we allow your account to access our repository, please add the repository to the `composer.json` in your Pimcore root folder (see [Composer repositories](https://getcomposer.org/doc/05-repositories.md#vcs)):

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@bitbucket.org:blackbitwerbung/pimcore-plugins-data-director"
    }
]
```

(Please [add your public SSH key to your Bitbucket account](https://support.atlassian.com/bitbucket-cloud/docs/add-access-keys/#Step-3.-Add-the-public-key-to-your-repository) for this to work)

Alternatively if you received the plugin code as zip file, please upload the zip file to your server - e.g. create a folder `bundles` in the Pimcore root folder) and add the following to your `composer.json`:

```json
"repositories": [
    {
        "type": "artifact",
        "url": "./bundles/"
    }
]
```

Beware that when you put the zip directly in the Pimcore root folder, and add `"url": "./"` it will still work but Composer will scan *all* files under the Pimcore root recursively to find bundle zip files (incl. assets, versions etc.) - which will take quite a long time.

Then you should be able to execute `composer require blackbit/data-director` (or `composer update blackbit/data-director --with-dependencies` for updates if you already have this bundle installed) from CLI.

At last, you have to enable and install the plugin by executing `./vendor/bin/pimcore-bundle-enable BlackbitDataDirectorBundle`

You can always access the latest version by executing `composer update blackbit/data-director --with-dependencies` on CLI.

### Migrations / Updates

To update the plugin itself, please put the zip file which you received into the folder `bundles` in the Pimcore root folder. This step is not necessary if you have access to the bundle's [Bitbucket repository](https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director).

Afterwards run `composer update blackbit/data-director --with-dependencies` to update the plugin to the latest version.

Modifications on the database scheme (migrations) get executed automatically, so you do not have to care for this.

Nevertheless, if you do not see the Data Director main menu icon in the Pimcore backend, it may be caused by a failed migration. You can execute the migrations manually via `bin/console pimcore:bundle:install BlackbitDataDirectorBundle` to see which migration fails.

## Documentation

You can find the documentation / manual at [https://blackbitdigitalcommerce.github.io/pimcore-data-director/](https://blackbitdigitalcommerce.github.io/pimcore-data-director/).

## Demo / Tutorials in Docker

Please install [Docker](https://docs.docker.com/get-docker/)

To run the demo you need to run `./docker-setup.sh` to create a docker container with the installed data director bundle. You can then access the Pimcore demo admin under <http://localhost:2000/admin>.

- User: admin
- Password: admin

The installation will need some time. When you installed it once and only want to restart the container, just call `docker-compose up -d`. You can stop it via `docker-composer stop`.

### Tutorials

When you want to try yourself what is shown in the [tutorial videos](https://www.youtube.com/playlist?list=PL4-QRNfdsdKIfzQIP-c9hRruXf0r48fjt) you will find all the examples including instructions, import data and finished solutions (dataport JSON files you can simply import if you do not know how to proceed) in the `examples/` folder.
