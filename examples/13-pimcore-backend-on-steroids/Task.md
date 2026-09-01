> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Pimcore on steroids

Data Director provides features to make daily tasks in Pimcore backend more efficient.

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-setup.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/13-pimcore-backend-on-steroids/import`
8. Create data object class `Brand` and import [its class definition](classes/class_Brand_export.json)
9. Create data object class `Product` and import [its class definition](classes/class_Product_export.json)
10. Create data object class `Category` and import [its class definition](classes/class_Category_export.json)
11. Create dataport [`Import Brands`](dataports/import-brands.json)
12. Create dataport [`Import Products`](dataports/import-products.json)

## Topics

-1. [Tree tooltips](https://opendxp.com/docs/platform/Pimcore/Tools_and_Features/Custom_Icons)
2. Translatable element keys
-3. [Path formatters](https://opendxp.com/docs/platform/Pimcore/Objects/Object_Classes/Class_Settings/Path_Formatter/) for better readability
4. [Object preview](https://opendxp.com/docs/platform/Pimcore/Objects/Object_Classes/Class_Settings/Preview_Generator)
-5. Back / Forward button to navigate in tabs
-6. Faster deletion of elements
7. Open objects by key fields
-8. Quick search
-9. [PIM perspective](https://opendxp.com/docs/platform/Pimcore/Tools_and_Features/Perspectives/)
10. Dashboard portlets