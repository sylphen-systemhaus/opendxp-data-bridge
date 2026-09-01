> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Advanced imports

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-install.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/3-import-special-cases/import`

## Topics
1. Create / Edit multiple objects from one raw data item (e.g. comma-separated list of categories in a products CSV file)
2. Automatic translation
3. Change Pimcore data object field type without losing data
4. Automatically generate data from other fields, e.g. generate URL slug based on product name
5. Import assets from filesystem folder

## Create / Edit multiple objects from one raw data item
1. Create new dataport:
    * Source data type: CSV
    * Import class: CoreShopCategory
    * Import resource: /import/products-with-categories.csv
    * Add raw data field `Categories`
    * map `Categories` to `Key` and activate this as key field
    * in callback function convert comma-separated category list to array, e.g. with [explode()](https://www.php.net/manual/en/function.explode.php)
2. When one or more key fields return array of values all combinations get handled separately.
   Start complete import to create all categories.
    * map `Categories` to `Name#en`, in callback function return `{{ key }}`
3. Create new dataport
    * Source data type: CSV
    * Import class: CoreShopProduct
    * Import resource: /import/products-with-categories.csv
    * Auto-create raw data fields (should result in `SKU`, `Product Name` and `Categories`)
    * Map `SKU` to `SKU`, activate key field checkbox
    * Map `Product Name` to `Name#en` and `Key`
    * Map `Weight` to `Weight`
    * Map `Categories` to `Categories` relation field -> in callback function use template for assignment by object path und edit function to convert comma-separated category string to array
    
Alternatively create 2 dataports and import [categories.json](./dataports/categories.json) and [products.json](./dataports/products.json) to set up everything as described above.
    
## Automatic translation
1. Put DeepL API key to `app/config/parameters.yml`:
```yaml
parameters:
    ...
    blackbit_data_director.deepl_api_key: <Your API key>
```
2. Go to Pimcore system settings -> Localization / Internationalization -> Add `German`
3. Create new dataport
    * Source data type: Pimcore
    * Import class: CoreShopProduct
    * Source data class: CoreShopProduct
    * Enable `Run automatically on new data` checkbox
    * Add raw data field `id` with data query selector `id`
    * Add raw data field `Description en` with data query selector `description#en`
    * Map `id` to `id`, activate `key field` checkbox
    * Map `Description en` to `Description#de`, in settings set `Auto-translate from` to `English`
    
Alternatively create new dataports and import [translation.json](./dataports/translation.json) to set up everything as described above.

## Change Pimcore data object field type without losing its data
1. Goal: Change field `weight` in class `CoreShopProduct` to a Quantity value field without losing data.
2. Create dataport 
    * Source data type: `Pimcore`
    * Import class: `CoreShopProduct`
    * Source data class: `CoreShopProduct`
    * Add raw data fields with data query selectors `id` and `weight`
4. Run raw data import
5. Add quantity value unit `kg`
6. Change data type of field `weight` to quantity value with allowed unit `kg` -> all set data on objects gets lost
7. Map `id` to `id`, activate `key field` checkbox
8. Map `weight` to `weight`, in settings assign callback function template `Import Kilogram (kg)`
9. Run `Pim import`

## Automatically generate data from other fields
1. Field content should be automatically generated but also manually editable
2. Create dataport
    * Source data type: `Pimcore`
    * Import class: `CoreShopProduct`
    * Source data class: `CoreShopProduct`
    * Enable `Run automatically on new data` checkbox
    * Add raw data fields with data query selectors `id`, `name#de` and `name#en`
    * Map `id` to `id`, activate `key field` checkbox
    * Map `Name en` to `URL`, set callback function to `return mb_strtolower(str_replace(' ', '-', $params['value']));`, activate `Do not overwrite if already filled` checkbox
    
Alternatively create new dataport dataports and import [url-slugs.json](./dataports/url-slugs.json) to set up everything as described above.

## Import assets from filesystem folder
1. Goal: Automatically import images with minimum resolution of 1000x1000 px.
2. Create new dataport
    * Source data type: File system
    * Import class: Asset
    * Import resource: `/var/www/html/data-director-bundle/examples/3-import-special-cases/import/images/*.jpg`
    * Asset target folder: `/images`
    * Add raw data field `File` without `CLI command`
    * Add raw data field `Filename` with `CLI command`: `basename $file`
    * Add raw data field `width` with `CLI command`: `identify -format '%w' $file`
    * Add raw data field `height` with `CLI command`: `identify -format '%h' $file`
    * Map `File` to `Stream`
    * Map `Filename` to `Filename` and set as key field, in callback function check for `$params['rawItemData']['width'] < 1000` and for `$params['rawItemData']['height'] < 1000` - if one is true, return `null`
    * Returning `null` for **all** callback fields means that raw data item will be ignored
    
Alternatively create new dataport dataports and import [assets.json](./dataports/assets.json) to set up everything as described above.
