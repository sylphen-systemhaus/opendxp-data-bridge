<?php
/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


namespace Sylphen\DataBridgeBundle\lib\Pim\Parser;

use InvalidArgumentException;
use OpenDxp\Model\Listing\AbstractListing;

class TypedArrayMapIterator extends ArrayMapIterator
{
    /** @var string */
    private $class;

    /**
     * @param AbstractListing|array $listing
     * @param $class
     */
    public function __construct($listing, $class)
    {
        if (is_object($listing) && method_exists($listing, 'getDao') && method_exists($listing->getDao(), 'loadIdList')) {
            parent::__construct($listing->loadIdList(), static function ($id) use ($class) {
                return $class::getById($id);
            });
        } elseif($listing instanceof AbstractListing) {
            parent::__construct($listing->load(), static function ($object) use ($class) {
                if(!$object instanceof $class) {
                    throw new InvalidArgumentException('Object of wrong class returned. Got "'.get_class($object).'", expected "'.$class.'"');
                }
                return $object;
            });
        } elseif(is_array($listing)) {
            parent::__construct($listing, static function ($object) use ($class) {
                if (!$object instanceof $class) {
                    throw new InvalidArgumentException('Object of wrong class returned. Got "'.(is_object($object)?get_class($object):gettype($object)).'", expected "'.$class.'"');
                }
                return $object;
            });
        } else {
            throw new InvalidArgumentException('Cannot handle this listing type');
        }

        $this->class = $class;
    }

    /**
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }
}