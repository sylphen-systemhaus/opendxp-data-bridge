> **Sylphen Data Bridge / OpenDXP:** Blackbit original (Pimcore, CoreShop, Bitbucket).
> Do not follow step-by-step on OpenDXP. See [examples/README.md](../README.md).

## News about import performance

Pimcore's default saving behaviour saves a lot of fields, even if they do not have changed, examples:

- [Validity of all fields gets checked on save](https://github.com/pimcore/pimcore/blob/131ec1750f20b35142afeb4d1a0addeb1edfd38c/models/DataObject/Concrete.php#L123-L168)
- [Dependencies get resolved from scratch on every save() call](https://github.com/pimcore/pimcore/blob/131ec1750f20b35142afeb4d1a0addeb1edfd38c/models/DataObject/Concrete.php#L370-L383) (
  via [AbstractObject::update()](https://github.com/pimcore/pimcore/blob/10.x/models/DataObject/AbstractObject.php#L897))
- etc.

Data Director refactored save logic to only execute database queries for changed fields, everything else stays the same. -> Performance increase of 300% compared to version 2.8 (incl. other performance optimizations than new save logic)
-> Much lower memory footprint