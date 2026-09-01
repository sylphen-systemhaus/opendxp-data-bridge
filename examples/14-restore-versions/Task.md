> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Restore versions

After some bulk operation (e.g. imports) it may happen that a lot of data in your data objects got wrong. But maybe you recognize this problem 1 week later while in the meantime other data has been maintained. How to restore the data to a previous state?

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-setup.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Add files" and upload `<repository root>/examples/14-restore-versions/import/2506_4189_compressed_flipkart_com-ecommerce_sample.csv.zip`
8. Create data object class `Brand` and import [its class definition](classes/class_Brand_export.json)
9. Create data object class `Product` and import [its class definition](classes/class_Product_export.json)
10. Create dataport "Import Brands" and import [its dataport definition](dataports/import-brands.json)
11. Create dataport "Import Products" and import [its dataport definition](dataports/import-products.json)

## Topics

1. [Options to restore data](#options-to-restore-data)
2. [Restore single fields to a previous state](#restore-single-fields-to-a-previous-state)
3. [Restore multiple objects to a previous state](#restore-multiple-objects-to-a-previous-state)

### Options to restore data

1. Restore database dump?

   In most cases this is the worst decision because you will lose all data which has been maintained in the meantime, not only in the data objects where the data got wrong but in all classes, documents, assets etc.
2. Restore data based on Pimcore versions

   Pimcore has a versioning system which allows you to restore data objects to a previous state. With Pimcore's default version restore feature, you can only restore all class fields to a previous state. And only for *one* object at a time.

   - But what if you only want to restore some fields but not all of them?
   - And in Pimcore default there is no way to restore versions of multiple objects.

### Restore single fields to a previous state

With Data Director you can restore single fields. 

### Restore multiple objects to a previous state

With Data Director you can roll back multiple objects to a previous state.

And of course this can be combined with the above mentioned feature to restore single fields to a previous state.