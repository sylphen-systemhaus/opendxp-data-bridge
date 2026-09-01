> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Grid exports

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-install.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/5-grid-exports/import`
8. Create new dataport `Flipkart Manufacturers`, import file [flipkart-manufacturers.json](dataports/flipkart-manufacturers.json) and run complete import
9. Create new dataport `Flipkart Products`, import file [flipkart-products.json](dataports/flipkart-products.json) and run complete import
10. Create new dataport `Product Export`, import file [product-export.csv.json](dataports/product-export.csv.json)

## Topics
1. Advantages of grid exports
2. Problems with Pimcore's built-in export functionality

## Advantages of grid exports

* easy to use filters (without SQL knowledge)
* easy selection of elements whose data shall be exported

## Problems with Pimcore's built-in export functionality

1. no reuse of previously generated export data -> export data is newly fetched from database when the export is requested -> slow
2. no predefined filters possible, e.g. to only export published objects
3. limited configuration of export format: currently only CSV and Excel are supported
4. Only possibility to download generated export file -> no possibility to automatically save as asset, upload to another server etc.
   * not possible to start an export and log out from Pimcore / shutdown your computer
   
Data Director's grid export solves all of those problems.
