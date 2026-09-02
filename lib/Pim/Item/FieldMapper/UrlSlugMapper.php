<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Item\FieldMapper;

use OpenDxp\Model\AbstractModel;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\Data\UrlSlug;
use OpenDxp\Model\Site;

class UrlSlugMapper extends AbstractFieldMapper
{
    public function supports(array $mapping, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        return $fieldDefinition instanceof Data\UrlSlug || $fieldDefinition->getFieldtype() === 'slug';
    }

    public function map($mapping, $value, $currentValue, Data $fieldDefinition, AbstractModel $dataObject = null)
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (is_array($value)) {
            $slugArray = [];
            foreach ($value as $siteDomain => $slug) {
                if ($siteDomain) {
                    $site = Site::getByDomain($siteDomain);

                    if (!$site instanceof Site) {
                        $this->log($dataObject, 'There is no site with domain "'.$siteDomain.'". Skipping URL slug for this site.', 'alert');
                        continue;
                    }
                    $slugArray[] = new UrlSlug($slug, $site->getId());
                } else {
                    $slugArray[] = new UrlSlug($slug);
                }
            }
            $value = $slugArray;
        }

        return $value;
    }
}