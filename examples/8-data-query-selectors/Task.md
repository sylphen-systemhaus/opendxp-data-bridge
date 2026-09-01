> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

# Data Query Selector Cheatsheet

Data Query Selectors are an essential part of the Data Director. They are used to refer to existing Pimcore elements (e.g. when importing relations) and to extract data from Pimcore elements.

## Referencing objects 

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>

<span style="color:blue">Class</span>:<span style="color:red">filterField</span>:<span style="color:green">filter value</span>

In PHP code this is equivalent to
```php
\OpenDxp\Model\DataObject\Product::getBySku(1234);
```

## Getting data

After object referencing parts, you can add fields which you want to get data from:

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">fullpath</span> will return `/products/1234`

You can chain the fields. The input value is taken from one data query selector part to the next:

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">image</span>:<span style="color:purple">fullpath</span> will return `/images/1234.jpg`

In PHP code this is equivalent to
```php
\OpenDxp\Model\DataObject\Product::getBySku(1234)->getFullPath();
\OpenDxp\Model\DataObject\Product::getBySku(1234)->getImage()->getFullPath();
```

### Getter parameters

Get localized field's value

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">name</span><span style="color:purple">#en</span> will return `ABC Bike`

Get thumbnail path of an image field

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">image</span>:<span style="color:purple">thumbnail#shop-detail</span> will return `/images/image-thumb__23__shop-detail/abc.jpg`

### Group functions

Get the names of all categories of a product:

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">categories</span>:<span style="color:purple">each(</span><span style="color:brown">name</span><span style="color:purple">)</span> will return `['Mountain Bikes', 'E-Bikes']`

### Retrieving multiple fields

Get the image's id and thumbnail path of an image gallery

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">images</span>:<span style="color:purple">each(</span><span style="color:brown">id</span>;<span style="color:grey">thumbnail#shop-detail</span><span style="color:purple">)</span> will
return 

```json
[
  ['id' => '23', 'thumbnail#shop-detail' => '/images/image-thumb__23__shop-detail/abc.jpg'],
  ['id' => '24', 'thumbnail#shop-detail' => '/images/image-thumb__24__shop-detail/abcd.jpg']
]
```

### Aliasing

Rename fields of a group

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">images</span>:<span style="color:purple">each(</span><span style="color:brown">id</span>;<span style="color:grey">thumbnail#shop-detail</span><span style="color:deeppink"> as thumbnailPath</span><span style="color:purple">)</span> will return

```json
[
  ['id' => '23', 'thumbnailPath' => '/images/image-thumb__23__shop-detail/abc.jpg'],
  ['id' => '24', 'thumbnailPath' => '/images/image-thumb__24__shop-detail/abcd.jpg']
]
```

### Filtering

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">categories</span>:<span style="color:deeppink">filter#published,true</span>:<span style="color:purple">each(</span><span style="color:brown">name</span><span style="color:purple">)</span> will
return `['Mountain Bikes']`

### Convenience helpers

#### Select fields

Get select field label (assumed option with label=`Germany` and value=`DE` is selected)

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">country</span>:<span style="color:purple">label</span> will return `Germany`

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">country</span>:<span style="color:purple">value</span> will return `DE`

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">country</span> will return `DE`

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">country</span>:<span style="color:purple">label</span><span style="color:deeppink">#de</span> will return `Deutschland`

#### URLs

Retrieve URL of Pimcore asset

<span style="color:blue">Product</span>:<span style="color:red">sku</span>:<span style="color:green">1234</span>:<span style="color:orange">image</span>:<span style="color:purple">url</span> will return `https://example.org/images/abc.jpg`

## Exports / Grid operator

Exports have a source class -> no need to provide object reference -> shorthand data query selectors:

<span style="color:orange">fullpath</span> will return `/products/1234`

<span style="color:orange">images</span>:<span style="color:purple">each(</span><span style="color:brown">fullpath</span>;<span style="color:grey">
channels</span><span style="color:purple">)</span> will return

```json
[
  ['fullpath' => '/images/abc.jpg', 'channels' => ['online', 'catalog']],
  ['fullpath' => '/images/abcd.jpg', 'channels' => ['online']]
]
```

But nevertheless you can also use complete data query selectors to get data from other objects:

<span style="color:blue">Brand</span>:<span style="color:red">name</span>:<span style="color:green">XYZ</span>:<span style="color:orange">id</span> will return `123`

### Enable / disable inheritance

Inheritance can be enabled / disabled on dataport level. But sometimes you only want to disable inheritance for a single field:

<span style="color:orange">withoutInheritance</span>:<span style="color:purple">sku</span> will return `''` for a product variant whose parent element has an SKU

### Finding reverse-related objects

When you have an export with source class `Brand`, you can access all products which reference the current brand in the field `brand` via

<span style="color:blue">Product</span>:<span style="color:red">brand</span>:<span style="color:green">.</span>:<span style="color:orange">each(</span><span style="color:purple">name</span><span style="color:orange">)</span> will return

```json
[
  ['ABC Bike', 'DEF Bike'], // product names of all products which for brand A
  ['Bicycle XYZ'] // product names of all products which for brand B
]
```

### Parameters / Create parametrized data-providing APIs

<span style="color:orange">image</span>:<span style="color:purple">thumbnail#</span><span style="color:deeppink">{{ thumbnail | default("shop-detail") }}</span> will return `/Images/image-thumb__23__shop-detail/abc.jpg` when called without URL / CLI parameter `thumbnail`

But when calling REST API endpoint `https://example.org/api/rest/export/thumbnail?thumbnail=shop-listing` this data query selector returns `/Images/image-thumb__23__shop-listing/abc.jpg`.

You could even create an export where you provide the data query selector for the export fields via URL parameters:

- `{{ field1 }}` as data query selector for a raw data field -> call via `https://example.org/api/rest/export/product-data?field1=name`
  - raw data field `field1` will now contain the content of requested field `name`
  - `{{ field2 }}` as data query selector for a raw data field -> call via `https://example.org/api/rest/export/product-data?field1=name&field2=image:thumbnail#shop-detail`
    - raw data field `field1` will contain content of field `name`
    - raw data field `field2` will contain thumbnail URL

## Summary: Advantages of Data Query Selectors

- unified query language for all data processing aspects:
  - imports
  - exports
  - grid operators
  - calculated value fields
- no PHP or Pimcore database knowledge necessary
- autocomplete support
- fast (because it gets compiled once to PHP code and later reused)
- error-tolerant
- supports convenience shortcuts for common use-cases