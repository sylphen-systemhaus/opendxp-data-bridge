# How to install / update vendor libraries

Ace comes from OpenDXP Admin (`/bundles/opendxpadmin/build/admin/ace-builds/`). Do not vendor a second copy.

## Swagger UI

Swagger gets used for the Rest API documentation. To update please execute
```shell script
# cd to bundle root folder
npm install
cp -r node_modules/swagger-ui-dist Resources/public/vendor/
```

## Vis-Network

Vis-Network gets used for the relations visualization. To update please execute
```shell script
# cd to bundle root folder
npm install
mkdir Resources/public/vendor/vis-network/
cp node_modules/vis-network/standalone/umd/vis-network.min.js Resources/public/vendor/vis-network/
cp node_modules/vis-network/styles/vis-network.min.css Resources/public/vendor/vis-network/
```