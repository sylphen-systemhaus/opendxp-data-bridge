> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Ways to execute imports

## Preparation

1. Install Docker (if not installed yet) -> https://hub.docker.com/editions/community/docker-ce-desktop-mac/
2. Clone https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director and go to branch "master", refresh it via `git pull`
    * if you do not have git, please download https://bitbucket.org/blackbitwerbung/pimcore-plugins-data-director/get/master.zip and unzip it
3. Go to the directory which you cloned / unzipped the files into
4. On command line run ./docker-install.sh and wait till this is finished
5. Go to http://localhost:2000/admin/ -> a Pimcore backend should appear
6. Login with User: admin, Password: admin
7. Right-click on Assets -> Home -> click "Import from Server" and import `/data-director-bundle/examples/2-ways-to-execute-imports`
8. Create dataports `Categories`, `Manufacturers` and `Products` and import `categories.json`, `manufacturers.json` as well as `products.json` from `/examples/2-ways-to-execute-imports/dataports` in your local data director repository.

Data is the same as in the example 1 - but this time formatted as XML file.

## Topics
1. Different ways of starting imports
    * manually via Pimcore backend
    * manually via right-click on target folder
    * via command line interface (CLI)
    * via REST API
    * automatically when import source changes
    * dependent imports
2. Archive folder
  
## Solution
* Import execution
    * manually in the Pimcore backend on the panel `History & manual import` with the buttons `Start rawdata import`, `Start PIM import` and `Start complete import`
        * single raw data items can be imported from the `Preview` tab (for testing)
    * manually by right-clicking the target folder in the data objects tree or on the import resource (if Pimcore assets are used as import resource)
    * Execute imports via CLI
        * login to server command line via `docker exec -it pimcore-php bash` (without docker you would have to login via SSH to your server)
        * `bin/console data-director:extract <Dataport-ID> [--rm]` - Parse source resource (e.g. file or URL) and write data to a flat database table (replace <Dataport-ID> with the real ID).
        
          If `--rm` is set, the imported file will get deleted after raw data import. For cronjob imports the `--rm` flag has to be set because otherwise the importer always imports from the same file.
        * `bin/console data-director:process <Dataport-ID> [Rawdata-ID] [--force]` - Maps raw data to Pimcore objects, updates exiting objects or creates new ones as needed.
         
          By default a raw data item gets only imported if the raw data for the found object changed since the last import (for performance reasons and to not overwrite manually edited values with old import data). By setting `--force` (or `-f`) you can bypass this check.
        * you can additionally use `-vvv` to get all log / error messages
        * `bin/console data-director:complete <Dataport-ID>` combines raw data extraction and data processing - it first executes `data-director:extract` and afterwards `data-director:process`
    * REST-API
        * enable [Pimcore REST API](https://opendxp.com/docs/latest/Development_Documentation/Web_Services/index.html#page_Permissions) and create API key for your user
        * API endpoint overview: http://localhost:2000/webservice/BlackbitDataDirector/rest (or with button `REST API documentation` below dataport tree)
        * call REST-API endpoint `POST http://localhost:2000/webservice/BlackbitDataDirector/rest/import/products`
            * if Request body provided, this will be used as import resource
        * if not logged in, you have to add parameter `apikey` to URLs to execute dataports
    * Automatic imports
        * enable `Run automatically on new data` on dataport settings
        * assign Pimcore asset as import resource for dataport
        * change asset or upload file to import resource folder to automatically start import
    * Dependent imports
        * start another import when current one finished
* Archive folder:
    * all imported import resources get saved there as files
    * useful for:
        * useful for error analysis (e.g. when you see in object history that a field got wrong content due to an import, you can look what the import file looked like)
        * backup irretrievable import resources, e.g. URLs