> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Object wizards - Create forms in Pimcore backend

## Preparation

1. [Install Docker](https://hub.docker.com/editions/community/docker-ce-desktop-mac/) (if not installed yet)
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please [download the bundle](https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip) and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-setup.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Create data object class `Brand` and import [its class definition](classes/class_Brand_export.json)
8. Create data object class `Product` and import [its class definition](classes/class_Product_export.json)
9. Right-click on Assets -> Home -> click "Import from Server" and import 

   `/data-director-bundle/examples/9-object-wizard/import`
10. Create new dataport `Import Brands`, [import dataport configuration](dataports/import-brands.json) and run complete import

## Topics

### Prerequisites

1. Run import via start menu -> import summary show which progress, changes, errors etc.
2. Object creation
   - Prerequisites
     - Object hierarchy is defined as
       - Brand (1st level)
           - Product (2nd level)
     - SKU is a mandatory field.
   - Create product `Gold ring` under Brand `Kiara Jewellery`
     - Default Pimcore way:
       - Find brand `Kiara Jewellery`
          - in optimal case, you can use the tree search (but it only searches for prefixes!)
          - in worst case you navigate manually in the tree or use the data object search and `show in tree` button
       - right-click `Kiara Jewellery`
       - select class `Product`
       - Enter `Gold ring` as object name
       - Enter product name *again*
       - Enter SKU as it is a mandatory field
       - Save & Publish
   - Object wizard way:
     - Create an object wizard form with 3 fields:
       - SKU
       - Name
       - Brand
     - accessible from main menu
     - focuses only on the fields which matter for this workflow
     - new object gets created in the desired position with 2 clicks
3. Move product `/Products/- without Brand -/Avon PK_504 Analog Watch - For Men, Boys` to `/Products/Avon`
   Problem: You cannot show both objects in the tree at the same time.

   Solution: Create an object wizard form with 2 fields:
   1. a field for setting one or multiple products
   2. a field for the target brand object which the products should be moved to
4. Create object wizard dataport to raise prices for all products of brand `Speedwav` by x %
5. Export products as JSON with fields `SKU`, `product name`, `price` via start menu object wizard
6. Create form (object wizard) to create brochure as Pimcore document for selected products