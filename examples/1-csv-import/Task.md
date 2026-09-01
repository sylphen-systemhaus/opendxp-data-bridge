> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Import data from CSV

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master"
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-install.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/1-csv-import`

## Task
Import [products.csv](products.csv) into Pimcore. The desired object tree is:

* Start
    * Manufacturers (folder)
        * Lime (CoreShopManufacturer)
        * Jumpy (CoreShopManufacturer)
    * Products (folder)
        * Trekking Bikes (CoreShopCategory)
            * Lime Rental Bike (CoreShopProduct)
        * Dirt Bikes (CoreShopCategory)
            * Dirt Flyer (CoreShopProduct)
      
Please create import dataports and fill the following fields (CSV column -> class field):

* CoreShopManufacturer
    * Manufacturer -> Name
* CoreShopCategory
    * Main Category -> Name#en
* CoreShopProduct
    * Product Number -> SKU
    * Product Name -> Name#en
    * Manufacturer -> Manufacturer
    * Main Category -> Categories
    * Main Image -> Images
  
## Solution

You can import the dataports from [solution folder](./solution) by creating dataports, right-clicking it and then select the desired file. Of course there are multiple valid solutions.

A video walkthrough is available under https://youtu.be/V4sUNEJ19Ig.