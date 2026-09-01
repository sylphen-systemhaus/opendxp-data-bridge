> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Data quality checks

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-setup.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Generate data object class `Category` and import [its class definition](classes/class_Category_export.json)
8. Generate data object class `Product` and import [its class definition](classes/class_Product_export.json)
9. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/6-data-quality/import`
10. Create new dataport `Import Products`, import file [flipkart-products.json](dataports/item-import-erp.json) and run complete import

## Topics
1. Data quality checks
2. (Half-)automated data review
3. Automatic translation (incl. possibility to manually translate certain terms)
4. Text generation
5. Automatic assignment of assets

## Data quality checks
- only allow publishing of an object if certain fields are filled
- display data quality checklist which has to be fulfilled for an object to be publishable
- generate report of products which do not fulfill data quality requirements and send to reviewer by email

## (Half-)automated data review / Workflows
- set up a Pimcore report to view the data quality fields -> base for content editors to find products which need some data maintenance
- use the same report as base for a periodic check for products which do not fulfill data qualitiy criteria but have not been changed for at least one week

## Automatic translation (incl. possibility to manually translate certain terms)

- Automatically translate text data (may also contain HTML) with DeepL / AWS Translate API
- Possibility to ignore or manually translate certain terms like brands or product names

## Text generation / Reuse other fields contents

- access other fields within Pimcore text fields
- generate texts based on object data and text bricks / rules with transparent rules
- manually changeable
- Example template:
  ```twig
  {{ name }} is one of the best {{ categories:0:name }}

  {% if categories:0:key == 'Shoes' %} 
  This shoe comes in a fantastic {{ technicalData:Shoes:color }} - absolutely trendy this spring.

  We have it in sizes {{ technicalData:Shoes:sizes }}
  {% endif %} 
  ```

## Automatic assignment of assets

- automatically assign uploaded asset to product, based on SKU in the asset filename
- move uploaded images to right folder -> keep folder structure clean