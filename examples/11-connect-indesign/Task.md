> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Use Pimcore data in InDesign / Easycatalog

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-setup.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/11-connect-indesign/import`
8. Create new dataport `Import Brands`, import file [import-brands.json](dataports/import-brands.json) and run complete import
9. Create new dataport `Import Products`, import file [import-products.json](dataports/import-products.json) and run complete import
10. Click `Permissions & API Keys` button below the dataport tree and set API key for your user to `f2802942615f443359f767751e23df5cf9b63af24dc2f9af0a281e802d1b663c`

## Topics

The InDesign plugin [Easycatalog](https://www.65bit.com/software/easycatalog/) provides an interface to connect data from external sources into InDesign. In this tutorial we will use the [XML Data Provider](https://www.65bit.com/software/easycatalog/modules/xml-data-provider/) to provide data to Easycatalog.

1. Set up an XML export which contains all the data which you want to use in InDesign
2. Use export URL in InDesign plugin `Easycatalog`
3. Create InDesign pages with the exported data