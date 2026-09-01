> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Exports

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-install.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/4-exports/import`
8. Create new dataport `Flipkart Manufacturers`, import file [flipkart-manufacturers.json](dataports/flipkart-manufacturers.json) and run complete import
9. Create new dataport `Flipkart Products`, import file [flipkart-products.json](dataports/flipkart-products.json) and run complete import
10. Click `Permissions & API Keys` button below the dataport tree and set API key for your user to `f2802942615f443359f767751e23df5cf9b63af24dc2f9af0a281e802d1b663c`

## Topics
1. Export CSV with custom fields from Pimcore backend
2. Automatic exports / Command Query Responsibility Separation (CQRS)
3. Access exports via URL
4. Data Director as backend for headless frontend applications

## Export CSV with custom fields from Pimcore backend

Exports are like imports a 2-step process:
1. Extract raw data from Pimcore objects
2. Format raw data to desired output format

Steps to create an export:

1. Create a new dataport named `Product export.csv`
2. Select source data type `Pimcore`, target class `Export`, source data class `CoreShopProduct` - this is the class whose data we want to export. 
3. Create raw data fields with *Auto create* button
4. In attribute mapping panel go to settings of `Result callback function`, select `Output raw data as CSV`
5. Start complete export on history panel to see generated export document.
6. Right-click to a folder which contains objects of the selected source data class (here `CoreShopProduct`) and select *Export > Product export.csv*

Annotations:

* Dataport name gets used as download file name
* Raw data field names get used as column headers
* Export items get ordered by order of raw data fields

### Exporting data from relations and other complex datatypes
You can chain data query selectors, e.g. `manufacturer:name` first gets related item in field `manufacturer` and frm the returned object the `name` gets fetched. You can also use PHP functions to execute, e.g. `manufacturer:name:strtoupper`. Function parameters can be given after `#`, e.g. `manufacturer:name#de` to get german name.

## Automatic exports / Command Query Responsibility Separation
Normally web applications use one database to save data to and read data from. Problem is that databases normally are optimized for write access through normalization. This makes exports slow because data has to be loaded from the database in the moment of the request.
CQRS on database level extends this concept by using the current database as single source of truth but also providing an optimized read model for the application.

1. Enable `Run automatically on new data` checkbox for dataport `Product export.csv`.
2. Run `Complete export` -> no change on first request
3. Run `Complete export` again -> immediate response

As soon as an element gets saved which is used in an export dataport as `source data class` and matches the default condition, the raw data for this single element in the dataport gets updated (based on which *raw data fields* are set as `key fields`). Thus raw data is always up-to-date by definition (actually it is *eventually consistent*) and so for exports raw data extraction step can be skipped - resulting in huge performance improvements.

Additionally exports support 

* browser cache: return `HTTP 304 Not Modified` if raw item with latest modification timestamp is not newer than last request timestamp in browser cache.
* server cache: export results are cached on file system, so users who do not have the export file in browser cache get the cached file returned (if raw item with latest modification timestamp is older than modification timestamp of server-side cache file)

## Access exports via URL

Under *REST API documentation* you see the REST endpoints for all dataports. With parameters you can individualize results:

* query: SQL condition to filter which data objects shall be used for export
* limit: limit number of returned datasets
* offset: skip first x result items
* locale: define language of localized fields (when data query selector only contains field name but no language, e.g. `name` instead of `name#de`)

## Data Director as backend for headless frontend applications

Imports and exports are not only to create file documents or import data from those. In fact every operation which changes elements in Pimcore is an import. Every operation which displays data from Pimcore elements can be seen as an export.

1. Add new number fields `clicks` to class `CoreShopProduct`
1. Create new dataport `Headless App: Search Products.json` and import [headless-app-search-products.json.json](dataports/headless-app-search-products.json.json)
2. Create new dataport `Headless App: Increase counter` and import [headless-app-increase-counter.json](dataports/headless-app-increase-counter.json)
3. Go to http://localhost:2000/BlackbitDataDirector/tutorial/, search for a product
   Product search accesses REST API endpoint of dataport `Headless App: Search Products.json` with adjusted `query` parameter
4. Clicking one found result item triggers REST API endpoint of dataport `Headless App: Increase counter`.
   